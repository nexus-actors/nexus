<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver\Exception;

use LogicException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use ReflectionNamedType;
use ReflectionParameter;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown by ParamResolverRegistry::compile() when no registered resolver
 * claims a parameter. Lists the well-known attributes/types the framework
 * recognises so users see actionable guidance instead of a generic error.
 */
final class UnresolvableParameterException extends LogicException
{
    public static function forParameter(ReflectionParameter $param, CompileContext $ctx): self
    {
        $reflectionType = $param->getType();
        $type = $reflectionType instanceof ReflectionNamedType
            ? $reflectionType->getName()
            : 'mixed';

        return new self(sprintf(
            'Cannot resolve %s parameter $%s: %s. Add #[FromActor(\'name\')], '
            . '#[FromService(Id::class)], #[FromBody], type-hint ServerRequestInterface / '
            . 'PerRequestActorScope, use a string for path params, or register a '
            . 'custom ParamResolver via $app->paramResolver(...).',
            $ctx->owner,
            $param->getName(),
            $type,
        ));
    }
}
