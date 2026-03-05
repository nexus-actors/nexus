<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleEmbeddedRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleEmbeddedRuntime::class)]
final class SwooleEmbeddedRuntimeTest extends TestCase
{
    #[Test]
    public function nameReturnsSwooleEmbedded(): void
    {
        $runtime = new SwooleEmbeddedRuntime();
        self::assertSame('swoole-embedded', $runtime->name());
    }

    #[Test]
    public function runIsNoOp(): void
    {
        $runtime = new SwooleEmbeddedRuntime();
        $runtime->run(); // must not throw, must not start Co\run()
        self::assertTrue(true);
    }

    #[Test]
    public function isRunningReturnsFalseBeforeRun(): void
    {
        $runtime = new SwooleEmbeddedRuntime();
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function isRunningReturnsTrueAfterRun(): void
    {
        $runtime = new SwooleEmbeddedRuntime();
        $runtime->run();
        self::assertTrue($runtime->isRunning());
    }

    #[Test]
    public function createMailboxReturnsMailbox(): void
    {
        $runtime = new SwooleEmbeddedRuntime();
        $mailbox = $runtime->createMailbox(MailboxConfig::unbounded());
        self::assertNotNull($mailbox);
    }
}
