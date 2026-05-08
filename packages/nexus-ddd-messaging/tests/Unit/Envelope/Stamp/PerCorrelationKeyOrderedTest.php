<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Envelope\Stamp;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp\PerCorrelationKeyOrdered;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(PerCorrelationKeyOrdered::class)]
final class PerCorrelationKeyOrderedTest extends TestCase
{
    #[Test]
    public function isFinalReadonlyImplementingStamp(): void
    {
        $reflection = new ReflectionClass(PerCorrelationKeyOrdered::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertContains(Stamp::class, $reflection->getInterfaceNames());
    }

    #[Test]
    public function exposesCorrelationKey(): void
    {
        $stamp = new PerCorrelationKeyOrdered('order-42');
        self::assertSame('order-42', $stamp->correlationKey);
    }
}
