<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Ask;

use Monadial\Nexus\Messenger\Ask\MessengerReplyRef;
use Monadial\Nexus\Messenger\Event\ReplyPublished;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Messenger\Tests\Support\FakeContextPropagator;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MessengerReplyRef::class)]
final class MessengerReplyRefTest extends TestCase
{
    #[Test]
    public function tellPublishesEnvelopeWithCorrelationIdStamp(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerReplyRef($sender, 'corr-abc', static function (): void {});
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        $stamp = $sender->sent[0]->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        self::assertSame('corr-abc', $stamp->id);
    }

    #[Test]
    public function tellFiresAckCallbackAfterSuccessfulPublish(): void
    {
        $sender = new RecordingSender();
        $ackFired = 0;
        $ref = new MessengerReplyRef($sender, 'corr-1', static function () use (&$ackFired): void {
            $ackFired++;
        });

        $ref->tell(new stdClass());

        self::assertSame(1, $ackFired);
    }

    #[Test]
    public function oneShotCallbackGuardInReceiverActorStyleClosurePreventsDoubleAck(): void
    {
        // Simulate the one-shot closure that ReceiverActor creates.
        $sender = new RecordingSender();
        $ackCount = 0;
        $fired = false;
        $ackCallback = static function () use (&$ackCount, &$fired): void {
            if ($fired) {
                return;
            }

            $fired = true;
            $ackCount++;
        };

        $ref = new MessengerReplyRef($sender, 'corr-oneshot', $ackCallback);

        $ref->tell(new stdClass());
        $ref->tell(new stdClass()); // second tell must not fire the callback again

        self::assertSame(1, $ackCount, 'Ack must be fired exactly once regardless of how many times tell() is called');
    }

    #[Test]
    public function tellDispatchesReplyPublishedEvent(): void
    {
        $sender = new RecordingSender();
        $dispatcher = new RecordingDispatcher();
        $message = new stdClass();
        $ref = new MessengerReplyRef($sender, 'corr-ev', static function (): void {}, events: $dispatcher);

        $ref->tell($message);

        self::assertCount(1, $dispatcher->events);
        $event = $dispatcher->events[0];
        self::assertInstanceOf(ReplyPublished::class, $event);
        self::assertSame($message, $event->message);
        self::assertSame('corr-ev', $event->correlationId);
    }

    #[Test]
    public function tellIncrementsRepliesSentCounter(): void
    {
        $sender = new RecordingSender();
        $observability = new RecordingObservability();
        $ref = new MessengerReplyRef($sender, 'corr-meter', static function (): void {}, $observability);

        $ref->tell(new stdClass());

        self::assertSame(1, $observability->meter->sum('nexus.messenger.replies.sent'));
    }

    #[Test]
    public function tellInjectsTraceContextStampWhenObservabilityEnabled(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerReplyRef(
            $sender,
            'corr-trace',
            static function (): void {},
            new RecordingObservability(new FakeContextPropagator()),
        );

        $ref->tell(new stdClass());

        $stamp = $sender->sent[0]->last(TraceContextStamp::class);
        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        self::assertSame(['traceparent' => 'fake-trace-id'], $stamp->carrier);
    }

    #[Test]
    public function askThrowsUnsupportedOperation(): void
    {
        $ref = new MessengerReplyRef(new RecordingSender(), 'corr-ask', static function (): void {});

        $this->expectException(UnsupportedOperationException::class);

        $ref->ask(new stdClass(), Duration::seconds(1));
    }

    #[Test]
    public function pathIsSyntheticUnderMessengerReply(): void
    {
        $ref = new MessengerReplyRef(new RecordingSender(), 'abc123', static function (): void {});

        self::assertSame('/messenger/reply/abc123', (string) $ref->path());
    }

    #[Test]
    public function pathSanitizesCorrelationIdForActorPathConstraints(): void
    {
        $ref = new MessengerReplyRef(new RecordingSender(), 'corr:id/special', static function (): void {});

        // Characters outside [a-zA-Z0-9_.-] are replaced with '-'.
        self::assertSame('/messenger/reply/corr-id-special', (string) $ref->path());
    }

    #[Test]
    public function isAliveAlwaysReturnsTrue(): void
    {
        $ref = new MessengerReplyRef(new RecordingSender(), 'corr-alive', static function (): void {});

        self::assertTrue($ref->isAlive());
    }
}
