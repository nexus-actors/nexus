<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Shutdown;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Symfony\Shutdown\GracefulShutdownHandler;
use Monadial\Nexus\Symfony\Shutdown\ShutdownTimeoutBehavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GracefulShutdownHandler::class)]
final class GracefulShutdownHandlerTest extends TestCase
{
    #[Test]
    public function shutdownCallsShutdownFnWithTimeout(): void
    {
        $called = false;
        $receivedTimeout = null;
        $timeout = Duration::seconds(5);

        $handler = new GracefulShutdownHandler(
            static function (Duration $t) use (&$called, &$receivedTimeout): void {
                $called = true;
                $receivedTimeout = $t;
            },
            $timeout,
            ShutdownTimeoutBehavior::ForceWithWarning,
        );

        $handler->shutdown();

        self::assertTrue($called);
        self::assertSame($timeout, $receivedTimeout);
    }
}
