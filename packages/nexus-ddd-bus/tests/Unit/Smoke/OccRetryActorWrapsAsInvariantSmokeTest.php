<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Actor profile + OCC: an `OptimisticLockException` is wrapped as the
 * terminal `ActorWriterInvariantViolation` and surfaces via `Either::left`.
 * The actor is the single writer; a lock collision is a wiring fault, not
 * a transient retry candidate.
 */
#[CoversClass(SyncCommandBus::class)]
final class OccRetryActorWrapsAsInvariantSmokeTest extends TestCase
{
    #[Test]
    public function actorProfileWrapsOptimisticLockAsTerminalInvariantViolation(): void
    {
        $harness = new PipelineHarness();
        $harness->profile = Profile::Actor;
        $handler = new OccRetryActorSmokeHandler();
        $harness->register(PlaceOrder::class, OccRetryActorSmokeHandler::class, $handler);
        $bus = $harness->build();

        $result = $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertTrue($result->isLeft());
        self::assertInstanceOf(ActorWriterInvariantViolation::class, $result->get());
        self::assertSame(
            1,
            $handler->attempts,
            'actor profile must not retry — single-writer invariant violation is terminal',
        );
    }
}

final class OccRetryActorSmokeHandler implements CommandHandler
{
    public int $attempts = 0;

    public function __invoke(PlaceOrder $command): void
    {
        $this->attempts++;

        throw new OptimisticLockException(PlaceOrder::class, $command->orderId, 1, 2);
    }
}
