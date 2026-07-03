<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Authorizer;
use Monadial\Nexus\Http\Auth\Exception\AuthorizationMisconfiguredException;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function json_decode;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    #[Test]
    public function passes_through_when_handler_has_no_auth_attributes(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()->withAttribute('_resolvedHandlerClass', PublicHandler::class);
        $response = $mw->process($req, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($next->wasCalled);
    }

    #[Test]
    public function returns_401_when_requires_auth_but_no_principal(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()->withAttribute('_resolvedHandlerClass', AuthRequiredHandler::class);
        $response = $mw->process($req, $next);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($next->wasCalled);
    }

    #[Test]
    public function returns_200_when_requires_auth_and_principal_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AuthRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice'));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());
    }

    #[Test]
    public function requires_scope_403_when_missing_any_required_scope(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', ScopeRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['orders.read']));

        $response = $mw->process($req, $next);

        self::assertSame(403, $response->getStatusCode());
        /** @var array{error: string, missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('forbidden', $body['error']);
        self::assertSame(['orders.write'], $body['missing']);
    }

    #[Test]
    public function requires_any_scope_200_when_any_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AnyScopeHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['orders.read']));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());
    }

    #[Test]
    public function requires_any_scope_403_when_none_present(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', AnyScopeHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['unrelated']));

        $response = $mw->process($req, $next);

        self::assertSame(403, $response->getStatusCode());
        /** @var array{missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['orders.read', 'orders.write'], $body['missing']);
    }

    #[Test]
    public function requires_role_works_analogously_to_scope(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $passing = $this->req()
            ->withAttribute('_resolvedHandlerClass', AdminHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', roles: ['admin']));
        self::assertSame(200, $mw->process($passing, $next)->getStatusCode());

        $failing = $this->req()
            ->withAttribute('_resolvedHandlerClass', AdminHandler::class)
            ->withAttribute('principal', new SimplePrincipal('bob', roles: ['guest']));
        self::assertSame(403, $mw->process($failing, $next)->getStatusCode());
    }

    #[Test]
    public function authorize_attribute_delegates_to_named_policy(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', PolicyHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice'));

        self::assertSame(200, $mw->process($req, $next)->getStatusCode());

        $denied = $this->req()
            ->withAttribute('_resolvedHandlerClass', PolicyHandler::class)
            ->withAttribute('principal', new SimplePrincipal('bob'));

        $response = $mw->process($denied, $next);
        self::assertSame(403, $response->getStatusCode());
        /** @var array{missing: list<string>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['missing'], 'Authorize failures have empty missing[] (opaque)');
    }

    #[Test]
    public function never_discloses_principal_claims_in_forbidden_body(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        $req = $this->req()
            ->withAttribute('_resolvedHandlerClass', ScopeRequiredHandler::class)
            ->withAttribute('principal', new SimplePrincipal('alice', scopes: ['some.private.scope']));

        $response = $mw->process($req, $next);
        $body = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('some.private.scope', $body);
        self::assertStringNotContainsString('alice', $body);
    }

    #[Test]
    public function fails_closed_when_run_before_router(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        // No '_nexus.routed' marker -> middleware ran before the router, i.e. it
        // was registered globally. Even with a resolved handler class present it
        // must refuse rather than pass through.
        $req = (new Psr17Factory())->createServerRequest('GET', '/test')
            ->withAttribute('_resolvedHandlerClass', AuthRequiredHandler::class);

        $this->expectException(AuthorizationMisconfiguredException::class);

        try {
            $mw->process($req, $next);
        } finally {
            self::assertFalse($next->wasCalled, 'Handler must not run when misconfigured');
        }
    }

    #[Test]
    public function passes_through_for_closure_handler_after_routing(): void
    {
        $next = new OkHandler();
        $mw = new AuthorizationMiddleware();

        // Routed, but the handler was a closure so no '_resolvedHandlerClass' is
        // set -> no reflectable class-level attributes to enforce.
        $req = $this->req();
        $response = $mw->process($req, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($next->wasCalled);
    }

    private function req(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/test')
            ->withAttribute('_nexus.routed', true);
    }
}

final class PublicHandler {}

#[RequiresAuth]
final class AuthRequiredHandler {}

#[RequiresScope('orders.read', 'orders.write')]
final class ScopeRequiredHandler {}

#[RequiresAnyScope('orders.read', 'orders.write')]
final class AnyScopeHandler {}

#[RequiresRole('admin')]
final class AdminHandler {}

#[Authorize(AlicePolicy::class)]
final class PolicyHandler {}

final class AlicePolicy implements Authorizer
{
    #[Override]
    public function authorize(Principal $principal, ServerRequestInterface $request): bool
    {
        return $principal->id() === 'alice';
    }
}

final class OkHandler implements RequestHandlerInterface
{
    public bool $wasCalled = false;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->wasCalled = true;

        return new Response(200);
    }
}
