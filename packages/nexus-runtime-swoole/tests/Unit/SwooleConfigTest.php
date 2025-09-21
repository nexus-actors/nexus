<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Swoole\SwooleConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleConfig::class)]
final class SwooleConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new SwooleConfig();
        self::assertSame(1000, $config->defaultMailboxCapacity);
        self::assertTrue($config->enableCoroutineHook);
        self::assertSame(100_000, $config->maxCoroutines);
    }

    #[Test]
    public function custom_values(): void
    {
        $config = new SwooleConfig(defaultMailboxCapacity: 500, enableCoroutineHook: false, maxCoroutines: 50_000);
        self::assertSame(500, $config->defaultMailboxCapacity);
        self::assertFalse($config->enableCoroutineHook);
        self::assertSame(50_000, $config->maxCoroutines);
    }

    #[Test]
    public function with_default_mailbox_capacity(): void
    {
        $config = new SwooleConfig();
        $updated = $config->withDefaultMailboxCapacity(2000);
        self::assertSame(2000, $updated->defaultMailboxCapacity);
        self::assertSame(1000, $config->defaultMailboxCapacity);
    }

    #[Test]
    public function with_enable_coroutine_hook(): void
    {
        $config = new SwooleConfig();
        $updated = $config->withEnableCoroutineHook(false);
        self::assertFalse($updated->enableCoroutineHook);
        self::assertTrue($config->enableCoroutineHook);
    }

    #[Test]
    public function with_max_coroutines(): void
    {
        $config = new SwooleConfig();
        $updated = $config->withMaxCoroutines(200_000);
        self::assertSame(200_000, $updated->maxCoroutines);
        self::assertSame(100_000, $config->maxCoroutines);
    }
}
