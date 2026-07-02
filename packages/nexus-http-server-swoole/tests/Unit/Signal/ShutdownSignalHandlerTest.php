<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Signal;

use Monadial\Nexus\Http\Server\Swoole\Signal\ShutdownSignalHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Http\Server;

use function class_exists;
use function extension_loaded;

#[CoversClass(ShutdownSignalHandler::class)]
final class ShutdownSignalHandlerTest extends TestCase
{
    #[Test]
    public function install_returns_void_and_does_not_throw(): void
    {
        if (!extension_loaded('swoole') || !class_exists(Server::class)) {
            self::markTestSkipped('Swoole extension not available');
        }

        // Swoole\Process::signal only meaningfully registers handlers inside a
        // running event loop. Outside of one, the call still validates the
        // method surface without invoking any handler.
        $server = new class ('127.0.0.1', 0) extends Server {
            public bool $shutdownCalled = false;

            public function shutdown(): bool
            {
                $this->shutdownCalled = true;

                return true;
            }
        };

        ShutdownSignalHandler::install($server, new NullLogger());

        self::assertFalse($server->shutdownCalled);
    }
}
