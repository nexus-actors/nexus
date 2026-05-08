<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Versioning;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Exception\UpcasterChainGapException;
use Monadial\Nexus\Ddd\Aggregate\Versioning\PayloadContext;
use Monadial\Nexus\Ddd\Aggregate\Versioning\Upcaster;
use Monadial\Nexus\Ddd\Aggregate\Versioning\UpcasterRegistry;
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
        $result = $pipeline->upcast('any.Event', 1, ['payload' => 'x'], $this->ctx());
        self::assertSame(['payload' => 'x'], $result);
    }

    #[Test]
    public function scanWithSingleUpcasterReturnsWorkingPipeline(): void
    {
        $pipeline = UpcasterRegistry::scan([SingleV1ToV2::class]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, ['payload' => 'v1'], $this->ctx());
        self::assertEqualsCanonicalizing(['payload' => 'v1', 'v2' => true], $result);
    }

    #[Test]
    public function scanWithChainV1ToV3RegistersBoth(): void
    {
        $pipeline = UpcasterRegistry::scan([ChainV1ToV2::class, ChainV2ToV3::class]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, [], $this->ctx());
        self::assertArrayHasKey('v2', $result);
        self::assertArrayHasKey('v3', $result);
    }

    #[Test]
    public function scanThrowsOnChainGap(): void
    {
        $this->expectException(UpcasterChainGapException::class);
        $this->expectExceptionMessageMatches('/orders\.OrderPlaced.*v2.*v3/');
        UpcasterRegistry::scan([GapV1ToV2::class, GapV3ToV4::class]);
    }

    private function ctx(): PayloadContext
    {
        return new PayloadContext('orders.OrderPlaced', 1, new DateTimeImmutable('2026-05-08T12:00:00+00:00'));
    }
}

final class SingleV1ToV2 implements Upcaster
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array
    {
        return [...$payload, 'v2' => true];
    }
}

final class ChainV1ToV2 implements Upcaster
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array
    {
        return [...$payload, 'v2' => true];
    }
}

final class ChainV2ToV3 implements Upcaster
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array
    {
        return [...$payload, 'v3' => true];
    }
}

final class GapV1ToV2 implements Upcaster
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array
    {
        return $payload;
    }
}

final class GapV3ToV4 implements Upcaster
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload, PayloadContext $context): array
    {
        return $payload;
    }
}
