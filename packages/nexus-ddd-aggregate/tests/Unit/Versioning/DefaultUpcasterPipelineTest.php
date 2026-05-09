<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Versioning;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcastContext;
use Monadial\Nexus\Ddd\Aggregate\Versioning\Upcaster;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultUpcasterPipeline::class)]
final class DefaultUpcasterPipelineTest extends TestCase
{
    #[Test]
    public function upcastWithNoUpcastersReturnsEventUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([]);
        $event = new OrderPlacedV1('order-1');
        $result = $pipeline->upcast('orders.OrderPlaced', 1, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    #[Test]
    public function upcastWithOneUpcasterTransformsEvent(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new OrderPlacedV1ToV2()],
        ]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, new OrderPlacedV1('order-1'), $this->ctx());
        self::assertInstanceOf(OrderPlacedV2::class, $result);
        self::assertSame('order-1', $result->orderId);
        self::assertNull($result->customerId);   // v2 default for the new field
    }

    #[Test]
    public function upcastWithChainV1ToV3WalksAllSteps(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [
                1 => new OrderPlacedV1ToV2(),
                2 => new OrderPlacedV2ToV3(),
            ],
        ]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, new OrderPlacedV1('order-1'), $this->ctx());
        self::assertInstanceOf(OrderPlacedV3::class, $result);
        self::assertSame('order-1', $result->orderId);
        self::assertSame('USD', $result->currency);   // v3 default
    }

    #[Test]
    public function upcastSkipsUpcastersForOtherEventNames(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new OrderPlacedV1ToV2()],
        ]);
        $event = new OrderPlacedV1('order-1');
        $result = $pipeline->upcast('orders.OrderShipped', 1, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    #[Test]
    public function upcastStartingAboveRegisteredFromVersionReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new OrderPlacedV1ToV2()],
        ]);
        $event = new OrderPlacedV2('order-1', null);
        $result = $pipeline->upcast('orders.OrderPlaced', 2, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    #[Test]
    public function upcastToStopsAtTargetVersion(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [
                1 => new OrderPlacedV1ToV2(),
                2 => new OrderPlacedV2ToV3(),
            ],
        ]);
        $result = $pipeline->upcastTo('orders.OrderPlaced', 1, 2, new OrderPlacedV1('order-1'), $this->ctx());
        self::assertInstanceOf(OrderPlacedV2::class, $result);
    }

    #[Test]
    public function upcastToWithTargetEqualToFromReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new OrderPlacedV1ToV2()],
        ]);
        $event = new OrderPlacedV1('order-1');
        $result = $pipeline->upcastTo('orders.OrderPlaced', 1, 1, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    #[Test]
    public function upcastToWithTargetBelowFromReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new OrderPlacedV1ToV2()],
        ]);
        $event = new OrderPlacedV2('order-1', null);
        $result = $pipeline->upcastTo('orders.OrderPlaced', 2, 1, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    private function ctx(): UpcastContext
    {
        return new UpcastContext('orders.OrderPlaced', 1, new DateTimeImmutable('2026-05-08T12:00:00+00:00'));
    }
}

final readonly class OrderPlacedV1 implements DomainEvent
{
    public function __construct(public string $orderId) {}
}

final readonly class OrderPlacedV2 implements DomainEvent
{
    public function __construct(public string $orderId, public ?string $customerId,) {}
}

final readonly class OrderPlacedV3 implements DomainEvent
{
    public function __construct(public string $orderId, public ?string $customerId, public string $currency,) {}
}

final class OrderPlacedV1ToV2 implements Upcaster
{
    public function eventName(): string
    {
        return 'orders.OrderPlaced';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function toVersion(): int
    {
        return 2;
    }

    public function upcast(DomainEvent $event, UpcastContext $context): DomainEvent
    {
        assert($event instanceof OrderPlacedV1);

        return new OrderPlacedV2($event->orderId, null);
    }
}

final class OrderPlacedV2ToV3 implements Upcaster
{
    public function eventName(): string
    {
        return 'orders.OrderPlaced';
    }

    public function fromVersion(): int
    {
        return 2;
    }

    public function toVersion(): int
    {
        return 3;
    }

    public function upcast(DomainEvent $event, UpcastContext $context): DomainEvent
    {
        assert($event instanceof OrderPlacedV2);

        return new OrderPlacedV3($event->orderId, $event->customerId, 'USD');
    }
}
