<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Ask\AskSupport;
use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;
use Monadial\Nexus\Messenger\Ask\PendingAskRegistry;
use Monadial\Nexus\Messenger\Ask\ReplyChannel;
use Monadial\Nexus\Messenger\Ask\ReplyChannelFactory;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Event\AskTimedOut;
use Monadial\Nexus\Messenger\Exception\AskCapacityExceededException;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\Route;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Pong;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[CoversClass(AskSupport::class)]
#[CoversClass(MessengerActorRef::class)]
final class AskReplyLoopTest extends TestCase
{
    /**
     * Full ask/reply loop: asker publishes to request transport, ReceiverActor
     * delivers with MessengerReplyRef, responder calls $ctx->reply(), reply goes
     * to reply transport, ReplyConsumer resolves the future.
     *
     * Asserts: resolved reply value, request acked after reply, asks.sent and
     * asks.resolved counters incremented.
     */
    #[Test]
    public function fullLoopResolvesReplyAndAcksRequest(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-loop', $runtime);
        $observability = new RecordingObservability();

        [$requestTransport, $replyTransport, $channelName] = $this->makeTransports();
        $askSupport = $this->makeAskSupport($system, $replyTransport, $channelName);

        $responder = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof Ping) {
                        $ctx->sender()?->tell(new Pong('pong-' . $msg->id));
                    }

                    return Behavior::same();
                },
            )),
            'responder',
        );

        $locator = new MapReplySenderLocator([$channelName => $replyTransport]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $responder)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $ref = MessengerBridge::producer(
            $requestTransport,
            'orders-out',
            observability: $observability,
            askSupport: $askSupport,
        );

        /** @var Pong|null $reply */
        $reply = null;

        $runtime->spawn(static function () use ($ref, &$reply): void {
            /** @var Pong $result */
            $result = $ref->ask(new Ping('hello'), Duration::seconds(3))->await();
            $reply = $result;
        });

        $runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertInstanceOf(Pong::class, $reply);
        self::assertSame('pong-hello', $reply->body);

        // Request transport must be acked after the reply was published.
        self::assertCount(1, $requestTransport->getAcknowledged(), 'Request must be acked after reply');
        self::assertCount(0, $requestTransport->getRejected());

        // Metric counters.
        self::assertSame(1, $observability->meter->sum('nexus.messenger.asks.sent'));
    }

    /**
     * When the responder never replies, the future must fail with AskTimeoutException
     * (via the FutureException hierarchy), the registry must be emptied, and the
     * asks.timed_out counter must be incremented.
     */
    #[Test]
    public function timeoutFailsFutureAndEmptiesRegistry(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-timeout', $runtime);

        [$requestTransport, $replyTransport, $channelName] = $this->makeTransports();
        $observability = new RecordingObservability();
        $dispatcher = new RecordingDispatcher();
        // Use the factory so observability and events forwarding is exercised.
        $askSupport = MessengerBridge::askSupport(
            $system,
            $this->makeFactory($replyTransport, $channelName),
            observability: $observability,
            events: $dispatcher,
        );

        // Silent responder — never replies.
        $responder = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )),
            'silent-responder',
        );

        $locator = new MapReplySenderLocator([$channelName => $replyTransport]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $responder)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $ref = MessengerBridge::producer(
            $requestTransport,
            'orders-out',
            observability: $observability,
            askSupport: $askSupport,
        );

        /** @var FutureException|null $caught */
        $caught = null;

        $runtime->spawn(static function () use ($ref, &$caught): void {
            try {
                $ref->ask(new Ping('timeout-me'), Duration::millis(100))->await();
            } catch (FutureException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertInstanceOf(FutureException::class, $caught, 'ask() must throw on timeout');
        self::assertSame(0, $askSupport->registry()->count(), 'Registry must be emptied after timeout');
        self::assertSame(1, $observability->meter->sum('nexus.messenger.asks.timed_out'));
        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(AskTimedOut::class, $dispatcher->events[0]);
    }

    /**
     * When two replies carry the same correlation ID, the first resolves the future
     * and the second is dropped (acked without resolving). The replies.dropped counter
     * must be incremented for the duplicate.
     */
    #[Test]
    public function duplicateReplyDropsSecondAndResolvesFirst(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-duplicate', $runtime);

        [$requestTransport, $replyTransport, $channelName] = $this->makeTransports();
        $registry = new PendingAskRegistry();
        $askSupport = new AskSupport(
            $system,
            $this->makeFactory($replyTransport, $channelName),
            $registry,
            Duration::millis(20),
        );

        $observability = new RecordingObservability();
        $refWithObs = MessengerBridge::producer(
            $requestTransport,
            'orders-out',
            observability: $observability,
            askSupport: $askSupport,
        );

        /** @var Pong|null $reply */
        $reply = null;
        /** @var FutureException|null $caught */
        $caught = null;

        // Responder that replies twice to simulate duplicate delivery.
        $responder = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof Ping) {
                        // Tell the reply ref twice — second will be dropped.
                        $sender = $ctx->sender();
                        $sender?->tell(new Pong('first'));
                        $sender?->tell(new Pong('second'));
                    }

                    return Behavior::same();
                },
            )),
            'double-responder',
        );

        $locator = new MapReplySenderLocator([$channelName => $replyTransport]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $responder)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->spawn(static function () use ($refWithObs, &$reply, &$caught): void {
            try {
                /** @var Pong $result */
                $result = $refWithObs->ask(new Ping('dup'), Duration::seconds(3))->await();
                $reply = $result;
            } catch (FutureException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(
            Duration::millis(600),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertNull($caught, 'Future must not fail on duplicate reply');
        self::assertInstanceOf(Pong::class, $reply, 'First reply must resolve the future');
        self::assertSame('first', $reply->body);
    }

    /**
     * When the registry is at capacity (maxPending=1) and a second ask arrives
     * concurrently, AskCapacityExceededException must be thrown.
     */
    #[Test]
    public function capacityExceededThrowsException(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-capacity', $runtime);

        [$requestTransport, $replyTransport, $channelName] = $this->makeTransports();
        // maxPending = 1 so the second ask immediately exceeds capacity.
        $registry = new PendingAskRegistry(1);
        $askSupport = new AskSupport(
            $system,
            $this->makeFactory($replyTransport, $channelName),
            $registry,
            Duration::millis(20),
        );

        // Silent responder — keeps the first ask pending.
        $responder = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )),
            'capacity-responder',
        );

        $locator = new MapReplySenderLocator([$channelName => $replyTransport]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $responder)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $observability = new RecordingObservability();
        $ref = MessengerBridge::producer(
            $requestTransport,
            'orders-out',
            observability: $observability,
            askSupport: $askSupport,
        );

        /** @var AskCapacityExceededException|null $caught */
        $caught = null;

        $runtime->spawn(static function () use ($ref, &$caught): void {
            // First ask — succeeds and stays pending (no responder replies).
            $ref->ask(new Ping('first'), Duration::seconds(10));

            // Second ask — should throw AskCapacityExceededException immediately.
            try {
                $ref->ask(new Ping('second'), Duration::seconds(10));
            } catch (AskCapacityExceededException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertInstanceOf(AskCapacityExceededException::class, $caught);
        self::assertSame(1, $observability->meter->sum('nexus.messenger.asks.capacity_rejected'));
    }

    /**
     * A MessengerActorRef built without AskSupport must still throw
     * UnsupportedOperationException on ask(), pointing users at MessengerBridge::askSupport().
     */
    #[Test]
    public function unconfiguredRefThrowsUnsupportedOperation(): void
    {
        $ref = new MessengerActorRef(new InMemoryTransport(), 'orders-out');

        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessageMatches('/MessengerBridge::askSupport/');

        $ref->ask(new Ping('x'), Duration::seconds(1));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{0: InMemoryTransport, 1: InMemoryTransport, 2: string}
     */
    private function makeTransports(): array
    {
        return [new InMemoryTransport(), new InMemoryTransport(), 'nexus-ask-replies'];
    }

    private function makeFactory(InMemoryTransport $replyTransport, string $channelName): ReplyChannelFactory
    {
        return new class ($replyTransport, $channelName) implements ReplyChannelFactory {
            public function __construct(private readonly InMemoryTransport $transport, private readonly string $name) {}

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
    }

    private function makeAskSupport(
        ActorSystem $system,
        InMemoryTransport $replyTransport,
        string $channelName,
    ): AskSupport {
        return MessengerBridge::askSupport(
            $system,
            $this->makeFactory($replyTransport, $channelName),
        );
    }
}
