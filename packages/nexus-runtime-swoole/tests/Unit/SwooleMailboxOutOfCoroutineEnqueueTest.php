<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Swoole\SwooleMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleMailbox::class)]
#[RequiresPhpExtension('swoole')]
final class SwooleMailboxOutOfCoroutineEnqueueTest extends TestCase
{
    #[Test]
    public function enqueueOutsideCoroutineDoesNotCrash(): void
    {
        // Called directly from main thread — no coroutine context.
        // Before T4 this crashed with `Swoole\Error: API must be called in the coroutine`.
        $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

        $result = $mailbox->enqueue(new stdClass());

        self::assertSame(EnqueueResult::Accepted, $result);
    }

    #[Test]
    public function enqueueOutsideCoroutineEventuallyDeliversMessage(): void
    {
        // Push from main thread, then drain from inside a coroutine.
        // The deferred push should land before our drain reads.
        $mailbox = new SwooleMailbox(MailboxConfig::unbounded());
        $msg = new stdClass();

        (void) $mailbox->enqueue($msg);

        $drained = null;
        run(static function () use ($mailbox, &$drained): void {
            // Brief sleep to give the deferred push coroutine a chance to run.
            Coroutine::sleep(0.01);
            $drained = $mailbox->dequeue();
        });

        self::assertSame($msg, $drained);
    }
}
