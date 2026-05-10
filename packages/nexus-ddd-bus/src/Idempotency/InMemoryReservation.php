<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Override;

/**
 * @psalm-immutable
 * @internal
 *
 * Reservation token emitted by `InMemoryIdempotencyStore`. The
 * `compositeKey` is the store's impl-private state — public so the store
 * can read it back on `markCompleted` / `release`.
 */
final readonly class InMemoryReservation implements IdempotencyReservation
{
    /** @param class-string $handlerClass */
    public function __construct(
        private string $handlerClass,
        private IdempotencyKey $idempotencyKey,
        public string $compositeKey,
    ) {}

    #[Override]
    public function handlerClass(): string
    {
        return $this->handlerClass;
    }

    #[Override]
    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }
}
