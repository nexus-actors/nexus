<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Full-pipeline smoke: dispatching a `PlaceOrder` command runs every
 * canonical middleware (start/end metrics + logs, validation/auth pass-through,
 * handler invocation, outbox flush). No idempotency commit under Sync per H6.
 */
#[CoversClass(SyncCommandBus::class)]
final class PlaceOrderEndToEndSmokeTest extends TestCase
{
    #[Test]
    public function dispatchInvokesHandlerEmitsMetricsAndFlushesOutbox(): void
    {
        $harness = new PipelineHarness();
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();
        $command = new PlaceOrder(customerId: 'cust-1', orderId: 'order-1');

        $result = $bus->tryDispatch($command);

        self::assertTrue($result->isRight());
        self::assertInstanceOf(Accepted::class, $result->get());

        self::assertCount(1, $handler->invocations);
        self::assertSame($command, $handler->invocations[0]);

        self::assertSame(1, $harness->outbox->flushCalls);

        $outcomes = array_map(
            static fn(array $r): string => (string) $r['tags']['outcome'],
            $harness->metrics->records,
        );
        self::assertContains(MetricOutcome::Started->value, $outcomes);
        self::assertContains(MetricOutcome::Succeeded->value, $outcomes);

        $logMessages = array_map(static fn(array $r): string => $r['message'], $harness->logger->records);
        self::assertContains('ddd.command.dispatched', $logMessages);
        self::assertContains('ddd.command.completed', $logMessages);
    }

    #[Test]
    public function syncProfileSkipsIdempotencyReservation(): void
    {
        $harness = new PipelineHarness();
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();

        $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertSame([], $harness->idempotencyStore->tryReserveCalls);
        self::assertSame([], $harness->idempotencyStore->markCompletedCalls);
    }
}
