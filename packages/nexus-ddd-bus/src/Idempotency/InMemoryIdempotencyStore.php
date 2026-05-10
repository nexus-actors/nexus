<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Override;

use function assert;

/**
 * @psalm-api
 *
 * Tests-only implementation. Production adapters live in
 * nexus-ddd-bus-idempotency-doctrine and nexus-ddd-idempotency-redis.
 *
 * Composite key shape is `{handlerClass}::{idempotencyKey}` so different
 * handlers consuming the same `IdempotencyKey` reserve independently.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, true> */
    private array $reserved = [];

    /** @var array<string, true> */
    private array $committed = [];

    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        $compositeKey = $handlerClass . '::' . $key->value;

        if (isset($this->committed[$compositeKey]) || isset($this->reserved[$compositeKey])) {
            return Option::none();
        }

        $this->reserved[$compositeKey] = true;

        return Option::some(new InMemoryReservation($handlerClass, $key, $compositeKey));
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        assert($token instanceof InMemoryReservation);
        unset($this->reserved[$token->compositeKey]);
        $this->committed[$token->compositeKey] = true;
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        assert($token instanceof InMemoryReservation);
        unset($this->reserved[$token->compositeKey]);
    }

    #[Override]
    public function ttl(): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit(30, TimeUnit::Days());
    }
}
