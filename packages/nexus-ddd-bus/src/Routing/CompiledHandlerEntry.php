<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Serializable equivalent of `ResolvedAttributesEntry` — same fields,
 * same shape, but designed to round-trip through `var_export`-style PHP
 * code generation. `BusBuilder::loadCompiledFrom()` lifts each
 * compiled entry into a `ResolvedAttributesEntry` at boot.
 */
final readonly class CompiledHandlerEntry
{
    /**
     * @param class-string $handlerClass
     * @param array<class-string, object> $attributes
     */
    public function __construct(
        public string $handlerClass,
        public array $attributes,
        public bool $authorizeBeforeValidate,
        public bool $idempotencyOptedOut,
    ) {}

    public function toResolvedAttributesEntry(): ResolvedAttributesEntry
    {
        return new ResolvedAttributesEntry(
            $this->handlerClass,
            $this->attributes,
            $this->authorizeBeforeValidate,
            $this->idempotencyOptedOut,
        );
    }
}
