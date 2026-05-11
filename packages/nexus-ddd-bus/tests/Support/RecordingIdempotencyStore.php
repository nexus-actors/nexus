<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use Override;

/**
 * Test fixture: records calls to each `IdempotencyStore` method so tests
 * can assert exception classification (markCompleted vs release) and
 * call counts. `tryReserve` always succeeds and emits a fresh
 * `InMemoryReservation` so the middleware has a token to thread.
 *
 * @psalm-api
 */
final class RecordingIdempotencyStore implements IdempotencyStore
{
    /** @var list<array{handlerClass: class-string, key: IdempotencyKey}> */
    public array $tryReserveCalls = [];

    /** @var list<IdempotencyReservation> */
    public array $markCompletedCalls = [];

    /** @var list<IdempotencyReservation> */
    public array $releaseCalls = [];

    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        $this->tryReserveCalls[] = ['handlerClass' => $handlerClass, 'key' => $key];

        return Option::some(new InMemoryReservation($handlerClass, $key, $handlerClass . '::' . $key->value));
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        $this->markCompletedCalls[] = $token;
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        $this->releaseCalls[] = $token;
    }

    #[Override]
    public function ttl(): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit(30, TimeUnit::Days());
    }
}
