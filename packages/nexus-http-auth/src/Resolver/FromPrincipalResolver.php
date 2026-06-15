<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Resolver;

use LogicException;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\RequestBoundContext;
use Override;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Recognises #[FromPrincipal] and reads the 'principal' request attribute
 * stamped by AuthenticationMiddleware.
 *
 * Only valid in request-bound scopes (HttpRequest, WsConnection). Throws at
 * compile time if used in HttpBoot — the principal is per-request, not
 * per-handler-instance.
 *
 * Register via $app->paramResolver(new FromPrincipalResolver()) in the
 * application bootstrap. The same resolver instance serves HTTP and WS
 * handlers automatically.
 */
final readonly class FromPrincipalResolver implements ParamResolver
{
    #[Override]
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
    {
        $attrs = $param->getAttributes(FromPrincipal::class);

        if ($attrs === []) {
            return null;
        }

        if (!$ctx->isRequestBound()) {
            throw new LogicException(
                "Cannot resolve {$ctx->owner}::__construct(\${$param->getName()}) via #[FromPrincipal] — "
                . 'principal is per-request; declare it on __invoke() instead (HTTP) or use '
                . 'the WebSocketHandler constructor (which runs per-connection).',
            );
        }

        $reflectionType = $param->getType();
        $type = $reflectionType instanceof ReflectionNamedType
            ? $reflectionType->getName()
            : null;

        return new ParamMetadata(resolver: $this, name: $param->getName(), type: $type);
    }

    #[Override]
    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
    {
        if (!$ctx instanceof RequestBoundContext) {
            throw new LogicException(
                "FromPrincipalResolver invoked outside a request-bound context for \${$metadata->name}",
            );
        }

        /** @var mixed $principal */
        $principal = $ctx->request->getAttribute('principal');

        if ($principal === null) {
            throw new LogicException(
                'Handler requested #[FromPrincipal] but no Principal on request — '
                . 'register AuthenticationMiddleware globally.',
            );
        }

        return $principal;
    }
}
