<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * H5 propagation contract: a `BusInvariantException` raised mid-pipeline
 * (here: synthesized via a recording middleware throwing
 * `DuplicateRoutingException`) PROPAGATES through `tryDispatch` instead of
 * lifting to `Either::left`. Boot-time misconfiguration is a wiring fault,
 * not a domain failure — adopters catch these at the composition root.
 */
#[CoversClass(SyncCommandBus::class)]
final class BusInvariantExceptionPropagatesThroughTryDispatchSmokeTest extends TestCase
{
    #[Test]
    public function tryDispatchPropagatesBusInvariantExceptionUnwrapped(): void
    {
        RecordingMiddleware::resetLog();
        $cause = DuplicateRoutingException::for(PlaceOrder::class, ['StrategyA: orders', 'StrategyB: reporting']);
        $harness = new PipelineHarness();
        $harness->extraMiddlewares = [new RecordingMiddleware('boot-invariant', throwOnEnter: $cause)];
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();

        $this->expectExceptionObject($cause);
        $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));
    }
}
