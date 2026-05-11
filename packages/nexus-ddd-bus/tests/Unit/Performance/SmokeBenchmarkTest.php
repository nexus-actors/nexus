<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Performance;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\PipelineHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function microtime;

/**
 * L5 smoke benchmark: 10000 dispatches of a no-op handler through the
 * canonical pipeline. Guards against accidental O(n^2) work in pipeline
 * composition.
 *
 * Threshold tradeoff: the plan calls for <50ms wall-clock, which targets
 * production opcache-on; dev containers ship with xdebug + no opcache so
 * the realistic dev ceiling is ~10x that. We assert a Docker-friendly
 * 1000ms cap — any regression that 2x's per-dispatch cost still trips it.
 */
#[CoversClass(SyncCommandBus::class)]
#[Group('performance')]
final class SmokeBenchmarkTest extends TestCase
{
    private const float MAX_ELAPSED_MS = 1_000.0;

    private const int DISPATCH_COUNT = 10_000;

    #[Test]
    public function tenThousandDispatchesStayUnderRegressionBudget(): void
    {
        $harness = new PipelineHarness();
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();
        $command = new PlaceOrder(customerId: 'cust-1', orderId: 'order-1');

        $start = microtime(true);

        for ($i = 0; $i < self::DISPATCH_COUNT; $i++) {
            $bus->tryDispatch($command);
        }

        $elapsedMs = (microtime(true) - $start) * 1_000;

        self::assertLessThan(
            self::MAX_ELAPSED_MS,
            $elapsedMs,
            sprintf(
                '%d dispatches took %.2fms; regression budget is %.0fms.',
                self::DISPATCH_COUNT,
                $elapsedMs,
                self::MAX_ELAPSED_MS,
            ),
        );
        self::assertCount(self::DISPATCH_COUNT, $handler->invocations);
    }
}
