<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Versioning;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use Monadial\Nexus\Ddd\Aggregate\Versioning\PayloadContext;
use Monadial\Nexus\Ddd\Aggregate\Versioning\Upcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultUpcasterPipeline::class)]
final class DefaultUpcasterPipelineTest extends TestCase
{
    #[Test]
    public function upcastWithNoUpcastersReturnsPayloadUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, ['payload' => 'v1'], $this->ctx());
        self::assertSame(['payload' => 'v1'], $result);
    }

    #[Test]
    public function upcastWithOneUpcasterTransformsPayload(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new V1ToV2()],
        ]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, ['payload' => 'v1'], $this->ctx());
        self::assertEqualsCanonicalizing(['added_in_v2' => true, 'payload' => 'v1'], $result);
    }

    #[Test]
    public function upcastWithChainV1ToV3WalksAllSteps(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [
                1 => new V1ToV2(),
                2 => new V2ToV3(),
            ],
        ]);
        $result = $pipeline->upcast('orders.OrderPlaced', 1, ['payload' => 'v1'], $this->ctx());
        self::assertEqualsCanonicalizing(
            ['added_in_v2' => true, 'added_in_v3' => 'three', 'payload' => 'v1'],
            $result,
        );
    }

    #[Test]
    public function upcastSkipsUpcastersForOtherEventNames(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new V1ToV2()],
        ]);
        $result = $pipeline->upcast('orders.OrderShipped', 1, ['payload' => 'shipping'], $this->ctx());
        self::assertSame(['payload' => 'shipping'], $result);
    }

    #[Test]
    public function upcastStartingAboveRegisteredFromVersionReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new V1ToV2()],
        ]);
        $result = $pipeline->upcast('orders.OrderPlaced', 2, ['payload' => 'v2'], $this->ctx());
        self::assertSame(['payload' => 'v2'], $result);
    }

    #[Test]
    public function upcastToStopsAtTargetVersion(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [
                1 => new V1ToV2(),
                2 => new V2ToV3(),
            ],
        ]);
        $result = $pipeline->upcastTo('orders.OrderPlaced', 1, 2, ['payload' => 'v1'], $this->ctx());
        self::assertEqualsCanonicalizing(['added_in_v2' => true, 'payload' => 'v1'], $result);
    }

    #[Test]
    public function upcastToWithTargetEqualToFromReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new V1ToV2()],
        ]);
        $result = $pipeline->upcastTo('orders.OrderPlaced', 1, 1, ['payload' => 'v1'], $this->ctx());
        self::assertSame(['payload' => 'v1'], $result);
    }

    #[Test]
    public function upcastToWithTargetBelowFromReturnsUnchanged(): void
    {
        $pipeline = new DefaultUpcasterPipeline([
            'orders.OrderPlaced' => [1 => new V1ToV2()],
        ]);
        $result = $pipeline->upcastTo('orders.OrderPlaced', 2, 1, ['payload' => 'v2'], $this->ctx());
        self::assertSame(['payload' => 'v2'], $result);
    }

    private function ctx(): PayloadContext
    {
        return new PayloadContext('orders.OrderPlaced', 1, new DateTimeImmutable('2026-05-08T12:00:00+00:00'));
    }
}

final class V1ToV2 implements Upcaster
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
        return [...$payload, 'added_in_v2' => true];
    }
}

final class V2ToV3 implements Upcaster
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
        return [...$payload, 'added_in_v3' => 'three'];
    }
}
