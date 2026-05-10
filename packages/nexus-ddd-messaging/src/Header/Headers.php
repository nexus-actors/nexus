<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Header;

use Fp\Functional\Option\Option;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Free-form header bag for cross-cutting message state. Keys are
 * dot-namespaced strings ('nexus.idempotency-key', 'nexus.causation.depth',
 * 'nexus.retry.budget_remaining_ms', 'nexus.principal-id'). Values are
 * scalars only — no objects, no arrays. Anything richer should ride on a
 * typed Stamp on the Envelope, not in this bag.
 *
 * `MessageMetadata::headers` is the single home for cross-cutting state
 * the bus and outbox carry between framework layers; downstream packages
 * MUST NOT introduce parallel header bags.
 */
final readonly class Headers
{
    /** @param array<string, scalar> $values */
    public function __construct(public array $values) {}

    #[NoDiscard('Headers::empty returns the empty bag — assign or use it')]
    public static function empty(): self
    {
        return new self([]);
    }

    /** @param array<string, scalar> $values */
    #[NoDiscard('Headers::of returns a new instance')]
    public static function of(array $values): self
    {
        return new self($values);
    }

    /** @return Option<scalar> */
    public function get(string $key): Option
    {
        return Option::fromNullable($this->values[$key] ?? null);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    #[NoDiscard('Headers::with returns a new instance')]
    public function with(string $key, int|float|string|bool $value): self
    {
        return clone($this, ['values' => [...$this->values, $key => $value]]);
    }

    #[NoDiscard('Headers::merge returns a new instance')]
    public function merge(self $other): self
    {
        return clone($this, ['values' => [...$this->values, ...$other->values]]);
    }
}
