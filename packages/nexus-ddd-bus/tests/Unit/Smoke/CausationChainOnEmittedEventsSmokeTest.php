<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingOutbox;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M7 contract assertion: after a successful handler invocation, the bus
 * calls `Outbox::flush()` exactly once. The actual causation-chain stamping
 * (`emitted.causationId = source.messageId`, `depth+1`) happens INSIDE the
 * outbox `flush()` implementation downstream (per `EventDrainMiddleware`
 * docblock, panel M7). The bus package's responsibility ends at calling
 * `flush()`; adapter packages owning a real Outbox impl ship the full
 * causation-chain assertion against their own integration tests.
 */
#[CoversClass(SyncCommandBus::class)]
final class CausationChainOnEmittedEventsSmokeTest extends TestCase
{
    #[Test]
    public function busCallsOutboxFlushExactlyOnceAfterSuccessfulHandler(): void
    {
        $harness = new PipelineHarness();
        $outbox = new RecordingOutbox();
        $harness->outbox = $outbox;
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();

        $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertSame(1, $outbox->flushCalls);
        self::assertSame(0, $outbox->discardCalls);
        self::assertCount(1, $handler->invocations);
    }
}
