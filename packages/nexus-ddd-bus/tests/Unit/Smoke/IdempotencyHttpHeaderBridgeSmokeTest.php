<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryIdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrderHandler;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L6 HTTP-header bridge: when the command class lacks `#[IdempotencyKey]`,
 * the envelope's `nexus.idempotency-key` header provides the dedup key.
 * Two dispatches with the same header value short-circuit the second.
 */
#[CoversClass(SyncCommandBus::class)]
final class IdempotencyHttpHeaderBridgeSmokeTest extends TestCase
{
    #[Test]
    public function secondDispatchWithIdenticalHeaderKeyShortCircuitsHandler(): void
    {
        $harness = new PipelineHarness();
        $harness->profile = Profile::Async;
        $harness->idempotencyStore = new InMemoryIdempotencyStore();
        $handler = new PlaceOrderHandler();
        $harness->register(PlaceOrder::class, PlaceOrderHandler::class, $handler);
        $bus = $harness->build();
        $first = $this->envelope($harness, 'http-req-1');
        $second = $this->envelope($harness, 'http-req-1');

        $bus->dispatchEnveloped($first);
        $bus->dispatchEnveloped($second);

        self::assertCount(
            1,
            $handler->invocations,
            'second dispatch with same HTTP idempotency-key header must short-circuit',
        );
    }

    private function envelope(PipelineHarness $harness, string $headerKey): Envelope
    {
        return new Envelope(
            new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'),
            MessageMetadata::root($harness->clock)
                ->withHeaders(Headers::of([HeaderKeys::IDEMPOTENCY_KEY => $headerKey])),
        );
    }
}
