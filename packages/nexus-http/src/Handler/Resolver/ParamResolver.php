<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use ReflectionParameter;

/**
 * @psalm-api
 *
 * Resolves a single handler parameter at two phases:
 *   compile()  — at handler resolve time (once per class). Decides whether
 *                this resolver handles the parameter and returns a
 *                ParamMetadata if yes, null to defer to the next resolver.
 *   resolve()  — at request/connection time. Produces the actual argument
 *                value from the metadata + the invocation context.
 *
 * The framework only ever calls resolve() with metadata produced by the
 * same resolver (back-ref dispatch via ParamMetadata::$resolver). Implementers
 * can therefore trust the metadata's payload shape unconditionally.
 */
interface ParamResolver
{
    public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata;

    public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed;
}
