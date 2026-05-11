<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Forward-shipped in Phase 10b → fleshed by Phase 12a. Re-use, do not
 * recreate, in Phase 12a; only `attribute()` is consumed today
 * (validation + authorization middleware). The other fields exist
 * for the Phase 12a `BusBuilder` to populate via reflection, and for
 * Phase 14's `RoutesShowCommand` to render.
 *
 * Per-handler attribute snapshot resolved once at boot.
 */
final readonly class ResolvedAttributesEntry
{
    /**
     * @param class-string $handlerClass
     * @param array<class-string, object> $attributes
     *
     * @psalm-suppress PossiblyUnusedMethod — populated by BusBuilder in Phase 12a.
     */
    public function __construct(
        /**
         * @psalm-suppress PossiblyUnusedProperty — rendered by Phase 14 RoutesShowCommand.
         */
        public string $handlerClass,
        public array $attributes,
        /**
         * @psalm-suppress PossiblyUnusedProperty — consumed by BusBuilder pipeline reorder in Phase 12a.
         */
        public bool $authorizeBeforeValidate,
        /**
         * @psalm-suppress PossiblyUnusedProperty — consumed by Phase 12a idempotency wiring.
         */
        public bool $idempotencyOptedOut,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return Option<T>
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    public function attribute(string $attributeClass): Option
    {
        return Option::fromNullable($this->attributes[$attributeClass] ?? null);
    }
}
