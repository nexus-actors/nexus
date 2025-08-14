<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Mailbox;

use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailboxConfig::class)]
final class MailboxConfigTest extends TestCase
{
    #[Test]
    public function boundedCreatesBoundedConfig(): void
    {
        $config = MailboxConfig::bounded(100);

        self::assertSame(100, $config->capacity);
        self::assertSame(OverflowStrategy::ThrowException, $config->strategy);
        self::assertTrue($config->bounded);
    }

    #[Test]
    public function boundedAcceptsCustomStrategy(): void
    {
        $config = MailboxConfig::bounded(50, OverflowStrategy::DropNewest);

        self::assertSame(50, $config->capacity);
        self::assertSame(OverflowStrategy::DropNewest, $config->strategy);
        self::assertTrue($config->bounded);
    }

    #[Test]
    public function unboundedCreatesUnboundedConfig(): void
    {
        $config = MailboxConfig::unbounded();

        self::assertSame(PHP_INT_MAX, $config->capacity);
        self::assertSame(OverflowStrategy::ThrowException, $config->strategy);
        self::assertFalse($config->bounded);
    }

    #[Test]
    public function withCapacityReturnsNewInstance(): void
    {
        $original = MailboxConfig::bounded(100);
        $updated = $original->withCapacity(200);

        self::assertNotSame($original, $updated);
        self::assertSame(100, $original->capacity);
        self::assertSame(200, $updated->capacity);
        self::assertSame($original->strategy, $updated->strategy);
        self::assertSame($original->bounded, $updated->bounded);
    }

    #[Test]
    public function withStrategyReturnsNewInstance(): void
    {
        $original = MailboxConfig::bounded(100, OverflowStrategy::ThrowException);
        $updated = $original->withStrategy(OverflowStrategy::DropOldest);

        self::assertNotSame($original, $updated);
        self::assertSame(OverflowStrategy::ThrowException, $original->strategy);
        self::assertSame(OverflowStrategy::DropOldest, $updated->strategy);
        self::assertSame($original->capacity, $updated->capacity);
        self::assertSame($original->bounded, $updated->bounded);
    }

    #[Test]
    public function immutabilityOriginalUnchangedAfterWithers(): void
    {
        $original = MailboxConfig::bounded(100, OverflowStrategy::ThrowException);

        $original->withCapacity(999);
        $original->withStrategy(OverflowStrategy::Backpressure);

        self::assertSame(100, $original->capacity);
        self::assertSame(OverflowStrategy::ThrowException, $original->strategy);
        self::assertTrue($original->bounded);
    }
}
