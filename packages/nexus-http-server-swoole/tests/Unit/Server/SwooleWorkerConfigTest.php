<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Server;

use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SwooleWorkerConfig::class)]
final class SwooleWorkerConfigTest extends TestCase
{
    #[Test]
    public function bind_captures_host_and_port(): void
    {
        $cfg = SwooleWorkerConfig::bind('0.0.0.0', 9000);
        self::assertSame('0.0.0.0', $cfg->host);
        self::assertSame(9000, $cfg->port);
    }

    #[Test]
    public function workers_returns_new_instance(): void
    {
        $a = SwooleWorkerConfig::bind('0.0.0.0', 8080);
        $b = $a->workers(8);

        self::assertNotSame($a, $b);
        self::assertSame(1, $a->workers);
        self::assertSame(8, $b->workers);
    }

    #[Test]
    public function defaults_are_sensible(): void
    {
        $cfg = SwooleWorkerConfig::bind('0.0.0.0', 8080);

        self::assertSame(1, $cfg->workers);
        self::assertSame(0, $cfg->reactorThreads);   // 0 = swoole default
        self::assertSame(0, $cfg->maxRequest);        // unlimited
        self::assertTrue($cfg->installSignalHandlers);
        self::assertInstanceOf(NullLogger::class, $cfg->logger);
    }
}
