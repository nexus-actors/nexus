<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sync profile + OCC retry: a handler that throws `OptimisticLockException`
 * on the first invocation and succeeds on the second produces a successful
 * dispatch with exactly two handler calls.
 */
#[CoversClass(SyncCommandBus::class)]
final class OccRetryRetriesAndRecoversSmokeTest extends TestCase
{
    #[Test]
    public function handlerSucceedsOnSecondAttemptAfterOptimisticLockFailure(): void
    {
        $harness = new PipelineHarness();
        $harness->retryBudgetMs = 1_000;
        $handler = new OccRetrySmokeFlakyHandler();
        $harness->register(PlaceOrder::class, OccRetrySmokeFlakyHandler::class, $handler);
        $bus = $harness->build();

        $result = $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertTrue($result->isRight());
        self::assertInstanceOf(Accepted::class, $result->get());
        self::assertSame(2, $handler->attempts, 'OCC retry must invoke handler twice — fail once, succeed once');
    }
}

final class OccRetrySmokeFlakyHandler implements CommandHandler
{
    public int $attempts = 0;

    public function __invoke(PlaceOrder $command): void
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            throw new OptimisticLockException(PlaceOrder::class, $command->orderId, 1, 2);
        }
    }
}
