<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A synthetic envelope with depth = cap triggers `CausationDepthExceededException`
 * on the +1 stamp. `dispatchEnveloped` is required so the test can preset
 * the header value verbatim (the canonical `tryDispatch` always constructs
 * a fresh root envelope with empty headers).
 */
#[CoversClass(SyncCommandBus::class)]
final class CausationDepthExceededSmokeTest extends TestCase
{
    #[Test]
    public function dispatchEnvelopedThrowsWhenDepthExceedsCap(): void
    {
        $harness = new PipelineHarness();
        $harness->causationDepthCap = 32;
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();
        $envelope = new Envelope(
            new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'),
            MessageMetadata::root($harness->clock)->withHeaders(Headers::of([HeaderKeys::CAUSATION_DEPTH => 32])),
        );

        $this->expectException(CausationDepthExceededException::class);
        $bus->dispatchEnveloped($envelope);
    }
}
