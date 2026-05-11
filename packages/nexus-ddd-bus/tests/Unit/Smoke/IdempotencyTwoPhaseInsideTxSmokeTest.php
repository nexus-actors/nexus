<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B2 ordering invariant: on handler success under Async profile, the
 * canonical pipeline performs `IdempotencyCommit.markCompleted` BEFORE
 * `EventDrain.flush`. The dedup row must be durable before the outbox
 * relays its events so a poll between flush and mark cannot double-deliver.
 */
#[CoversClass(SyncCommandBus::class)]
final class IdempotencyTwoPhaseInsideTxSmokeTest extends TestCase
{
    #[Test]
    public function markCompletedHappensBeforeOutboxFlushOnHandlerSuccess(): void
    {
        $timeline = new IdempotencyTwoPhaseTimeline();
        $harness = new PipelineHarness();
        $harness->profile = Profile::Async;
        $harness->idempotencyStore = new IdempotencyTwoPhaseStore($timeline);
        $harness->outbox = new IdempotencyTwoPhaseOutbox($timeline);
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();

        $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertSame(
            ['tryReserve', 'markCompleted', 'flush'],
            $timeline->events,
            'two-phase invariant: reserve outer, commit inner, flush after commit',
        );
        self::assertCount(1, $handler->invocations);
    }
}

final class IdempotencyTwoPhaseTimeline
{
    /** @var list<string> */
    public array $events = [];
}

final class IdempotencyTwoPhaseStore implements IdempotencyStore
{
    public function __construct(private readonly IdempotencyTwoPhaseTimeline $timeline) {}

    #[Override]
    public function tryReserve(string $handlerClass, IdempotencyKey $key): Option
    {
        $this->timeline->events[] = 'tryReserve';

        return Option::some(new InMemoryReservation($handlerClass, $key, $handlerClass . '::' . $key->value));
    }

    #[Override]
    public function markCompleted(IdempotencyReservation $token): void
    {
        $this->timeline->events[] = 'markCompleted';
    }

    #[Override]
    public function release(IdempotencyReservation $token): void
    {
        $this->timeline->events[] = 'release';
    }

    #[Override]
    public function ttl(): FiniteDuration
    {
        return FiniteDuration::fromTimeUnit(30, TimeUnit::Days());
    }
}

final class IdempotencyTwoPhaseOutbox implements Outbox
{
    public function __construct(private readonly IdempotencyTwoPhaseTimeline $timeline) {}

    /** @param Option<MessageId> $producerId */
    #[Override]
    public function appendCommand(Command $command, Option $producerId): void
    {
        $this->timeline->events[] = 'appendCommand';
    }

    /** @param Option<MessageId> $producerId */
    #[Override]
    public function appendEvent(DomainEvent $event, Option $producerId): void
    {
        $this->timeline->events[] = 'appendEvent';
    }

    #[Override]
    public function flush(): void
    {
        $this->timeline->events[] = 'flush';
    }

    #[Override]
    public function discard(): void
    {
        $this->timeline->events[] = 'discard';
    }
}
