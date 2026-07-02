<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;

/**
 * @psalm-api
 *
 * Reflection results for one handler class — captured once at compile.
 * Cache-friendly: every field is a var_export-safe scalar/array.
 */
final readonly class HandlerMetadata
{
    /**
     * @param list<ParamMetadata> $ctorParams
     * @param list<ParamMetadata> $invokeParams
     */
    public function __construct(
        public string $className,
        public string $invokeMethod,
        public array $ctorParams,
        public array $invokeParams,
        public bool $returnIsFuture,
        public bool $needsRequestScope,
    ) {}
}
