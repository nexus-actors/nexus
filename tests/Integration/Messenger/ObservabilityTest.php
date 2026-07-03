<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Event\MessageConsumed;
use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Event\MessageRejected;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Tests\Support\FakeContextPropagator;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSpan;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_filter;
use function array_values;
use function assert;

#[CoversClass(MessengerBridge::class)]
final class ObservabilityTest extends TestCase
{
    #[Test]
    public function consumedMessagesEmitSpansCountersAndEvents(): void
    {
        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $dispatcher = new RecordingDispatcher();
        $system = ActorSystem::create(
            'obs-test',
            $runtime,
            eventDispatcher: $dispatcher,
            observability: $observability,
        );
        $transport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg->id;
                }

                return Behavior::same();
            },
        )), 'target');

        $system->spawn(MessengerBridge::receiverProps(
            $transport,
            new MapMessageRouter([Ping::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            null,
            $system->eventDispatcher(),
        ), 'receiver');

        $producer = MessengerBridge::producer(
            $transport,
            'pings-out',
            null,
            $observability,
            $system->eventDispatcher(),
        );
        $producer->tell(new Ping('a'));
        $producer->tell(new Ping('b'));

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['a', 'b'], $received);

        $consumed = array_values(array_filter(
            $dispatcher->events,
            static fn(object $event): bool => $event instanceof MessageConsumed,
        ));

        self::assertCount(2, $consumed);
        self::assertInstanceOf(MessageConsumed::class, $consumed[0]);
        self::assertSame((string) $target->path(), $consumed[0]->targetPath);

        $published = array_filter(
            $dispatcher->events,
            static fn(object $event): bool => $event instanceof MessagePublished,
        );

        self::assertCount(2, $published);
        self::assertSame(2, $observability->meter->sum('nexus.messenger.messages.sent'));
        self::assertSame(2, $observability->meter->sum('nexus.messenger.messages.consumed'));

        $ackedReceiveSpans = array_filter(
            $observability->tracer->spans,
            static fn(RecordingSpan $span): bool => $span->name === 'messenger.receive'
                && ($span->attributes['nexus.messenger.outcome'] ?? null) === 'acked',
        );

        self::assertNotEmpty($ackedReceiveSpans);
    }

    #[Test]
    public function unroutableMessagesEmitRejectionTelemetry(): void
    {
        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $dispatcher = new RecordingDispatcher();
        $system = ActorSystem::create(
            'obs-unroutable',
            $runtime,
            eventDispatcher: $dispatcher,
            observability: $observability,
        );
        $transport = new InMemoryTransport();
        $message = new Ping('lost');
        $transport->send(new Envelope($message));

        $system->spawn(MessengerBridge::receiverProps(
            $transport,
            new MapMessageRouter([]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            null,
            $system->eventDispatcher(),
        ), 'receiver');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertCount(1, $transport->getRejected());

        $rejected = array_values(array_filter(
            $dispatcher->events,
            static fn(object $event): bool => $event instanceof MessageRejected,
        ));

        self::assertCount(1, $rejected);
        self::assertInstanceOf(MessageRejected::class, $rejected[0]);
        self::assertSame($message, $rejected[0]->message);
        self::assertSame(1, $observability->meter->sum('nexus.messenger.messages.rejected'));

        $rejectedSpans = array_filter(
            $observability->tracer->spans,
            static fn(RecordingSpan $span): bool => $span->name === 'messenger.receive'
                && ($span->attributes['nexus.messenger.outcome'] ?? null) === 'rejected',
        );

        self::assertNotEmpty($rejectedSpans);
    }

    #[Test]
    public function traceContextPropagatesFromProducerToConsumerReceiveSpan(): void
    {
        $runtime = new FiberRuntime();
        $observability = new RecordingObservability(new FakeContextPropagator());
        $dispatcher = new RecordingDispatcher();
        $system = ActorSystem::create(
            'trace-prop-test',
            $runtime,
            eventDispatcher: $dispatcher,
            observability: $observability,
        );
        $transport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg->id;
                }

                return Behavior::same();
            },
        )), 'target');

        $system->spawn(MessengerBridge::receiverProps(
            $transport,
            new MapMessageRouter([Ping::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            null,
            $system->eventDispatcher(),
            $observability,
        ), 'receiver');

        $producer = MessengerBridge::producer(
            $transport,
            'pings-out',
            null,
            $observability,
            $system->eventDispatcher(),
        );
        $producer->tell(new Ping('x'));

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['x'], $received);

        $receiveSpans = array_values(array_filter(
            $observability->tracer->spans,
            static fn(RecordingSpan $span): bool => $span->name === 'messenger.receive',
        ));

        self::assertNotEmpty($receiveSpans);
        assert($receiveSpans[0] instanceof RecordingSpan);
        self::assertNotNull($receiveSpans[0]->parent);
        self::assertSame(str_repeat('a', 32), $receiveSpans[0]->parent->spanContext->traceId);
    }
}
