<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Ask\AskSupport;
use Monadial\Nexus\Messenger\Ask\PendingAskRegistry;
use Monadial\Nexus\Messenger\Ask\ReplyChannel;
use Monadial\Nexus\Messenger\Ask\ReplyChannelFactory;
use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Messenger\Tests\Support\FakeContextPropagator;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

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
    public function tellAttachesTraceContextStampWhenObservabilityIsEnabled(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef(
            $sender,
            'orders-out',
            null,
            new RecordingObservability(new FakeContextPropagator()),
        );

        $ref->tell(new stdClass());

        $stamp = $sender->sent[0]->last(TraceContextStamp::class);

        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        self::assertSame(['traceparent' => 'fake-trace-id'], $stamp->carrier);
    }

    #[Test]
    public function askRecordsSpanNamedMessengerAskAndEndsIt(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test-ask-span', $runtime);
        $replyTransport = new InMemoryTransport();
        $channelName = 'test-replies';

        $factory = new class ($replyTransport, $channelName) implements ReplyChannelFactory {
            public function __construct(private readonly InMemoryTransport $transport, private readonly string $name,) {}

            public function create(): ReplyChannel
            {
                $transport = $this->transport;
                $name = $this->name;

                return new class ($transport, $name) implements ReplyChannel {
                    public function __construct(
                        private readonly InMemoryTransport $transport,
                        private readonly string $name,
                    ) {}

                    public function name(): string
                    {
                        return $this->name;
                    }

                    public function receiver(): ReceiverInterface
                    {
                        return $this->transport;
                    }

                    public function close(): void
                    {
                        // No-op: InMemoryTransport has no lifecycle to clean up.
                    }
                };
            }
        };

        $askSupport = new AskSupport($system, $factory, new PendingAskRegistry(), Duration::millis(20));
        $observability = new RecordingObservability();
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out', null, $observability, null, $askSupport);

        $ref->ask(new stdClass(), Duration::seconds(1));

        self::assertCount(1, $observability->tracer->spans);

        $span = $observability->tracer->spans[0];

        self::assertSame('messenger.ask', $span->name);
        self::assertSame(SpanKind::Producer, $span->kind);
        self::assertSame([
            'messaging.operation' => 'ask',
            'messaging.system' => 'symfony-messenger',
            'nexus.message.type' => stdClass::class,
            'nexus.messenger.sender' => 'orders-out',
        ], $span->attributes);
        self::assertTrue($span->ended);
    }

    #[Test]
    public function pathIsSyntheticAndRefIsAlwaysAlive(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        self::assertSame('/messenger/orders-out', (string) $ref->path());
        self::assertTrue($ref->isAlive());
    }
}
