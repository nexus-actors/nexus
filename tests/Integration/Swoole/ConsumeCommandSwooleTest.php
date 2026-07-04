<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Console\ConsumeCommand;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Verifies that ConsumeCommand works with SwooleRuntime.
 *
 * ConsumeCommand accepts Runtime by injection — SwooleRuntime is a zero-arg
 * drop-in for FiberRuntime. The transport is pre-seeded before execute()
 * (plain array operations, no coroutine context required). The watchdog
 * triggers graceful shutdown once the --limit is reached, so run() returns.
 */
#[CoversNothing]
#[RequiresPhpExtension('swoole')]
final class ConsumeCommandSwooleTest extends TestCase
{
    #[Test]
    public function consumesPreSeededMessagesOnSwooleRuntimeAndReturnsSuccess(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));

        // DeadLetterRef does not implement BackpressureCapable; ReceiverActor
        // falls into the plain tell() branch which acks the envelope and
        // increments the processed count — so the watchdog fires after 3 messages.
        $router = new MapMessageRouter([stdClass::class => new DeadLetterRef()]);

        $command = new ConsumeCommand(
            new SwooleRuntime(),
            $transport,
            $router,
        );

        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--limit'         => '3',
            '--poll-interval' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
