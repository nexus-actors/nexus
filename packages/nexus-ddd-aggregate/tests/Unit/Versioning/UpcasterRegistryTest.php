<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Versioning;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Exception\UpcasterChainGapException;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcastContext;
use Monadial\Nexus\Ddd\Aggregate\Versioning\Upcaster;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcasterRegistry;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpcasterRegistry::class)]
final class UpcasterRegistryTest extends TestCase
{
    #[Test]
    public function scanWithEmptyInputReturnsEmptyPipeline(): void
    {
        $pipeline = UpcasterRegistry::scan([]);
        $event = new RegV1('id-1');
        $result = $pipeline->upcast('any.Event', 1, $event, $this->ctx());
        self::assertSame($event, $result);
    }

    #[Test]
    public function scanWithSingleUpcasterReturnsWorkingPipeline(): void
    {
        $pipeline = UpcasterRegistry::scan([SingleRegV1ToV2::class]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, new RegV1('id-1'), $this->ctx());
        self::assertInstanceOf(RegV2::class, $result);
    }

    #[Test]
    public function scanWithChainV1ToV3RegistersBoth(): void
    {
        $pipeline = UpcasterRegistry::scan([ChainRegV1ToV2::class, ChainRegV2ToV3::class]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, new RegV1('id-1'), $this->ctx());
        self::assertInstanceOf(RegV3::class, $result);
    }

    #[Test]
    public function scanThrowsOnChainGap(): void
    {
        $this->expectException(UpcasterChainGapException::class);
        $this->expectExceptionMessageMatches('/orders\.OrderPlaced.*v2.*v3/');
        UpcasterRegistry::scan([GapRegV1ToV2::class, GapRegV3ToV4::class]);
    }

    private function ctx(): UpcastContext
    {
        return new UpcastContext('orders.OrderPlaced', 1, new DateTimeImmutable('2026-05-08T12:00:00+00:00'));
    }
}

final readonly class RegV1 implements DomainEvent
{
    public function __construct(public string $id) {}
}

final readonly class RegV2 implements DomainEvent
{
    public function __construct(public string $id) {}
}

final readonly class RegV3 implements DomainEvent
{
    public function __construct(public string $id) {}
}

final class SingleRegV1ToV2 implements Upcaster
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
        assert($event instanceof RegV1);

        return new RegV2($event->id);
    }
}

final class ChainRegV1ToV2 implements Upcaster
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
        assert($event instanceof RegV1);

        return new RegV2($event->id);
    }
}

final class ChainRegV2ToV3 implements Upcaster
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
        assert($event instanceof RegV2);

        return new RegV3($event->id);
    }
}

final class GapRegV1ToV2 implements Upcaster
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
        return $event;
    }
}

final class GapRegV3ToV4 implements Upcaster
{
    public function eventName(): string
    {
        return 'orders.OrderPlaced';
    }

    public function fromVersion(): int
    {
        return 3;
    }

    public function toVersion(): int
    {
        return 4;
    }

    public function upcast(DomainEvent $event, UpcastContext $context): DomainEvent
    {
        return $event;
    }
}
