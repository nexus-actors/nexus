<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Versioning;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Versioning\PayloadContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadContext::class)]
final class PayloadContextTest extends TestCase
{
    #[Test]
    public function exposesEventNameFromVersionAndOccurredAt(): void
    {
        $now = new DateTimeImmutable('2026-05-08T12:00:00+00:00');
        $ctx = new PayloadContext('orders.OrderPlaced', 1, $now);
        self::assertSame('orders.OrderPlaced', $ctx->eventName);
        self::assertSame(1, $ctx->fromVersion);
        self::assertSame($now, $ctx->occurredAt);
    }
}
