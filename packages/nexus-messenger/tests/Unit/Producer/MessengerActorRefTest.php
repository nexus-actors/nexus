<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MessengerActorRef::class)]
final class MessengerActorRefTest extends TestCase
{
    #[Test]
    public function tellWrapsTheMessageInAnEnvelopeAndSends(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out');
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        self::assertNull($sender->sent[0]->last(SourceActorPathStamp::class));
    }

    #[Test]
    public function tellStampsTheSourcePathWhenConfigured(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out', ActorPath::fromString('/user/emitter'));

        $ref->tell(new stdClass());

        $stamp = $sender->sent[0]->last(SourceActorPathStamp::class);

        self::assertInstanceOf(SourceActorPathStamp::class, $stamp);
        self::assertSame('/user/emitter', $stamp->path);
    }

    #[Test]
    public function askThrowsUnsupportedOperation(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        $this->expectException(UnsupportedOperationException::class);

        $ref->ask(new stdClass(), Duration::seconds(1));
    }

    #[Test]
    public function tellRecordsSpanAndCounterAndDispatchesMessagePublished(): void
    {
        $sender = new RecordingSender();
        $observability = new RecordingObservability();
        $dispatcher = new RecordingDispatcher();
        $ref = new MessengerActorRef($sender, 'orders-out', null, $observability, $dispatcher);
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertCount(1, $observability->tracer->spans);

        $span = $observability->tracer->spans[0];

        self::assertSame('messenger.send', $span->name);
        self::assertSame(SpanKind::Producer, $span->kind);
        self::assertSame([
            'messaging.operation' => 'send',
            'messaging.system' => 'symfony-messenger',
            'nexus.message.type' => stdClass::class,
            'nexus.messenger.sender' => 'orders-out',
        ], $span->attributes);
        self::assertTrue($span->ended);
        self::assertSame(1, $observability->meter->sum('nexus.messenger.messages.sent'));
        self::assertCount(1, $dispatcher->events);

        $event = $dispatcher->events[0];

        self::assertInstanceOf(MessagePublished::class, $event);
        self::assertSame($message, $event->message);
        self::assertSame('orders-out', $event->senderName);
    }

    #[Test]
    public function tellDispatchesMessagePublishedEvenWhenObservabilityIsDisabled(): void
    {
        $sender = new RecordingSender();
        $dispatcher = new RecordingDispatcher();
        $ref = new MessengerActorRef($sender, 'orders-out', null, events: $dispatcher);
        $message = new stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(MessagePublished::class, $dispatcher->events[0]);
    }

    #[Test]
    public function pathIsSyntheticAndRefIsAlwaysAlive(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        self::assertSame('/messenger/orders-out', (string) $ref->path());
        self::assertTrue($ref->isAlive());
    }
}
