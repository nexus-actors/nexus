<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Middleware;

use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\AuthChallenge;
use Monadial\Nexus\Http\Auth\Authorizer;
use Monadial\Nexus\Http\Auth\Exception\AuthorizationMisconfiguredException;
use Monadial\Nexus\Http\Auth\Exception\InvalidAuthorizerException;
use Monadial\Nexus\Http\Auth\Principal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

use function array_diff;
use function array_intersect;
use function array_values;
use function count;
use function explode;
use function is_string;
use function is_subclass_of;
use function json_encode;
use function str_contains;

/**
 * @psalm-api
 *
 * Reads the handler class set by RouterMiddleware on the
 * '_resolvedHandlerClass' request attribute. Reflects on it once (cached
 * by class name) for #[RequiresAuth] / #[RequiresScope] / #[RequiresRole]
 * / #[Authorize] attributes.
 *
 * Decisions:
 *   - no attributes on the class -> pass through
 *   - any attribute + no Principal -> 401 + WWW-Authenticate
 *   - Principal lacks required scope/role -> 403 with `missing` list
 *   - Authorize policy returns false -> 403 with empty `missing`
 *
 * Register PER-ROUTE, never globally. It reads the handler class that
 * RouterMiddleware resolves, so it must run AFTER routing — which only happens
 * for route-level middleware. Registering it globally makes it run before the
 * router; it detects that and fails closed (500) rather than letting requests
 * through unchecked.
 *
 *   $app->middleware(new AuthenticationMiddleware($authenticator));
 *   $app->get('/me', MeHandler::class)->middleware(AuthorizationMiddleware::class);
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    /** @var array<class-string, AuthMetadata> */
    private array $metaCache = [];

    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct(
        private readonly AuthChallenge $challenge = new AuthChallenge('Bearer', 'api'),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? new Psr17Factory();
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('_nexus.routed') !== true) {
            // Running before the router resolved a handler -> registered globally.
            // Fail closed rather than let the request through unchecked.
            throw AuthorizationMisconfiguredException::ranBeforeRouter();
        }

        /** @var mixed $handlerClassAttr */
        $handlerClassAttr = $request->getAttribute('_resolvedHandlerClass');

        if (!is_string($handlerClassAttr) || $handlerClassAttr === '') {
            // Router ran but the handler is a closure (no reflectable class),
            // so there are no class-level auth attributes to enforce.
            return $handler->handle($request);
        }

        // _resolvedHandlerClass may be "Class" or "Class::method" - strip method suffix.
        $handlerClass = str_contains($handlerClassAttr, '::')
            ? explode('::', $handlerClassAttr, 2)[0]
            : $handlerClassAttr;

        /** @var class-string $handlerClass */
        $meta = $this->metadata($handlerClass);

        if (!$meta->hasAnyAttribute()) {
            return $handler->handle($request);
        }

        /** @var mixed $principalAttr */
        $principalAttr = $request->getAttribute('principal');

        if (!$principalAttr instanceof Principal) {
            $this->logger->info('auth.unauthenticated', ['handler' => $handlerClass]);

            return $this->unauthorized();
        }

        $missing = $this->checkAttributes($meta, $principalAttr, $request);

        if ($missing !== null) {
            $this->logger->info('auth.forbidden', [
                'handler' => $handlerClass,
                'missing' => $missing,
                'principalId' => $principalAttr->id(),
            ]);

            return $this->forbidden($missing);
        }

        return $handler->handle($request);
    }

    /**
     * @return list<string>|null  null = allowed, list = denied with missing constraints
     */
    private function checkAttributes(AuthMetadata $meta, Principal $principal, ServerRequestInterface $request): ?array
    {
        foreach ($meta->requiresScope as $required) {
            $missing = array_values(array_diff($required, $principal->scopes()));

            if ($missing !== []) {
                return $missing;
            }
        }

        foreach ($meta->requiresAnyScope as $anyOf) {
            if (array_intersect($anyOf, $principal->scopes()) === []) {
                return $anyOf;
            }
        }

        foreach ($meta->requiresRole as $required) {
            $missing = array_values(array_diff($required, $principal->roles()));

            if ($missing !== []) {
                return $missing;
            }
        }

        foreach ($meta->requiresAnyRole as $anyOf) {
            if (array_intersect($anyOf, $principal->roles()) === []) {
                return $anyOf;
            }
        }

        foreach ($meta->authorize as $authorizerClass) {
            if (!is_subclass_of($authorizerClass, Authorizer::class)) {
                throw InvalidAuthorizerException::notAnAuthorizer($authorizerClass);
            }

            $authorizer = new $authorizerClass();

            if (!$authorizer->authorize($principal, $request)) {
                return [];
            }
        }

        return null;
    }

    /** @param list<string> $missing */
    private function forbidden(array $missing): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(403, 'Forbidden')
            ->withHeader('Content-Type', 'application/json');

        $response->getBody()->write(
            (string) json_encode(['error' => 'forbidden', 'missing' => $missing]),
        );

        return $response;
    }

    /** @param class-string $handlerClass */
    private function metadata(string $handlerClass): AuthMetadata
    {
        if (isset($this->metaCache[$handlerClass])) {
            return $this->metaCache[$handlerClass];
        }

        $ref = new ReflectionClass($handlerClass);

        $requiresAuth = count($ref->getAttributes(RequiresAuth::class)) > 0;
        $requiresScope = [];
        $requiresAnyScope = [];
        $requiresRole = [];
        $requiresAnyRole = [];
        $authorize = [];

        foreach ($ref->getAttributes(RequiresScope::class) as $a) {
            $requiresScope[] = $a->newInstance()->scopes;
        }

        foreach ($ref->getAttributes(RequiresAnyScope::class) as $a) {
            $requiresAnyScope[] = $a->newInstance()->scopes;
        }

        foreach ($ref->getAttributes(RequiresRole::class) as $a) {
            $requiresRole[] = $a->newInstance()->roles;
        }

        foreach ($ref->getAttributes(RequiresAnyRole::class) as $a) {
            $requiresAnyRole[] = $a->newInstance()->roles;
        }

        foreach ($ref->getAttributes(Authorize::class) as $a) {
            $authorize[] = $a->newInstance()->authorizer;
        }

        return $this->metaCache[$handlerClass] = new AuthMetadata(
            $requiresAuth,
            $requiresScope,
            $requiresAnyScope,
            $requiresRole,
            $requiresAnyRole,
            $authorize,
        );
    }

    private function unauthorized(): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(401, 'Unauthorized')
            ->withHeader('WWW-Authenticate', $this->challenge->toHeader())
            ->withHeader('Content-Type', 'application/json');

        $response->getBody()->write(
            (string) json_encode(['error' => 'unauthenticated']),
        );

        return $response;
    }
}
