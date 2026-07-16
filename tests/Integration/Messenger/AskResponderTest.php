<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Ask\MapReplySenderLocator;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\Route;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\ReplyToStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\DoReply;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Pong;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(ReceiverActor::class)]
final class AskResponderTest extends TestCase
{
    /**
     * Full responder path: request envelope has both stamps, responder actor replies
     * via $ctx->reply(), and the broker request is acked only AFTER the reply is sent
     * (process-ack ordering guarantee).
     */
    #[Test]
    public function fullResponderPathWithAckOrdering(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-full', $runtime);
        $requestTransport = new InMemoryTransport();
        $replySender = new RecordingSender();

        $correlationId = 'abc123def456';
        $replyChannel = 'replies';

        $requestTransport->send(
            (new Envelope(new Ping('req-1')))
                ->with(new CorrelationIdStamp($correlationId))
                ->with(new ReplyToStamp($replyChannel)),
        );

        // Responder: on Ping, saves the MessengerReplyRef as $savedSender and schedules
        // DoReply after 150 ms to create a timing gap that makes the ack-ordering assertion
        // meaningful. $ctx->sender() on DoReply would be null (scheduled-to-self has no
        // sender), so we capture the ref from the Ping handler where it is set.
        $savedSender = null;
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$savedSender): Behavior {
                    if ($msg instanceof Ping) {
                        $savedSender = $ctx->sender();
                        $ctx->scheduleOnce(Duration::millis(150), new DoReply());

                        return Behavior::same();
                    }

                    if ($msg instanceof DoReply) {
                        $savedSender?->tell(new Pong('hi'));

                        return Behavior::same();
                    }

                    return Behavior::unhandled();
                },
            )),
            'responder',
        );

        $locator = new MapReplySenderLocator([$replyChannel => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        // At 100 ms the DoReply schedule has not yet fired; the request must still be un-acked.
        $ackedAt100ms = null;
        $runtime->scheduleOnce(
            Duration::millis(100),
            static function () use ($requestTransport, &$ackedAt100ms): void {
                $ackedAt100ms = count($requestTransport->getAcknowledged());
            },
        );

        $runtime->scheduleOnce(
            Duration::millis(500),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // Ack ordering: not acked before reply, acked after.
        self::assertSame(0, $ackedAt100ms, 'Request must not be acked before the reply is published');
        self::assertCount(1, $requestTransport->getAcknowledged(), 'Request must be acked after reply');
        self::assertCount(0, $requestTransport->getRejected());

        // Reply transport received exactly one envelope with the correct correlation ID and body.
        self::assertCount(1, $replySender->sent);
        $replyEnvelope = $replySender->sent[0];
        $corrStamp = $replyEnvelope->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $corrStamp);
        self::assertSame($correlationId, $corrStamp->id);
        self::assertInstanceOf(Pong::class, $replyEnvelope->getMessage());
        self::assertSame('hi', $replyEnvelope->getMessage()->body);
    }

    /**
     * When the reply-to channel is not registered in the locator, the envelope is
     * rejected for redelivery without being delivered to the target actor.
     */
    #[Test]
    public function unknownReplyToChannelRejectsWithoutDelivery(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-unknown', $runtime);
        $requestTransport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    $received[] = $msg;

                    return Behavior::same();
                },
            )),
            'responder',
        );

        $requestTransport->send(
            (new Envelope(new Ping('unknown-ch')))
                ->with(new CorrelationIdStamp('corr-unknown'))
                ->with(new ReplyToStamp('nonexistent-channel')),
        );

        // Locator does not contain 'nonexistent-channel'.
        $locator = new MapReplySenderLocator(['other-channel' => new RecordingSender()]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertCount(0, $received, 'Message must not be delivered to the target actor');
        self::assertCount(0, $requestTransport->getAcknowledged());
        self::assertGreaterThanOrEqual(1, count($requestTransport->getRejected()));
    }

    /**
     * When no ReplySenderLocator is configured, ask-stamped envelopes are delivered
     * as plain tell + normal ack, matching non-ask behavior.
     */
    #[Test]
    public function noLocatorDeliversAsPlainTellAndAcks(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-no-locator', $runtime);
        $requestTransport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    $received[] = $msg;

                    return Behavior::same();
                },
            )),
            'responder',
        );

        $requestTransport->send(
            (new Envelope(new Ping('plain-1')))
                ->with(new CorrelationIdStamp('corr-plain'))
                ->with(new ReplyToStamp('replies')),
        );

        // No locator — ReceiverActor::create() called without the last param.
        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertCount(1, $received);
        self::assertInstanceOf(Ping::class, $received[0]);
        self::assertCount(1, $requestTransport->getAcknowledged());
        self::assertCount(0, $requestTransport->getRejected());
    }

    /**
     * Regression: a successfully acked ask must be pruned from the pending map immediately
     * so expiry never sees it. Without the fix, expiry calls reject() on an already-acked
     * envelope (double settlement — AMQP protocol error) and emits a false
     * nexus.messenger.asks.responder_expired count.
     *
     * Set askPendingTimeout to 100 ms; responder replies immediately. After 300 ms the
     * timeout window has passed multiple times. The expiry counter must remain zero and
     * no envelope must be rejected.
     */
    #[Test]
    public function ackedAskIsPrunedFromPendingMapNoSpuriousExpiry(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-prune', $runtime);
        $requestTransport = new InMemoryTransport();
        $replySender = new RecordingSender();
        $observability = new RecordingObservability();

        $correlationId = 'prune-corr-001';
        $replyChannel = 'replies';

        $requestTransport->send(
            (new Envelope(new Ping('prune-req')))
                ->with(new CorrelationIdStamp($correlationId))
                ->with(new ReplyToStamp($replyChannel)),
        );

        // Responder replies immediately on Ping — ack happens well before any expiry tick.
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof Ping) {
                        $ctx->sender()?->tell(new Pong('reply'));
                    }

                    return Behavior::same();
                },
            )),
            'fast-responder',
        );

        $locator = new MapReplySenderLocator([$replyChannel => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()
                    ->withPollInterval(Duration::millis(20))
                    ->withAskPendingTimeout(Duration::millis(100)),
                null,
                null,
                null,
                $observability,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        self::assertCount(1, $requestTransport->getAcknowledged(), 'Request must be acked after reply');
        self::assertCount(0, $requestTransport->getRejected(), 'Acked ask must not reach expiry — zero redeliveries');
        self::assertSame(
            0,
            $observability->meter->sum('nexus.messenger.asks.responder_expired'),
            'Expiry counter must be zero — acked ask was pruned from the pending map',
        );
    }

    /**
     * When the responder never replies, the pending ask is rejected for redelivery
     * once askPendingTimeout elapses.
     */
    #[Test]
    public function responderExpiryRejectsForRedelivery(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-expiry', $runtime);
        $requestTransport = new InMemoryTransport();
        $replySender = new RecordingSender();

        $requestTransport->send(
            (new Envelope(new Ping('expiry-req')))
                ->with(new CorrelationIdStamp('corr-expiry'))
                ->with(new ReplyToStamp('replies')),
        );

        // Target actor deliberately ignores the message and never replies.
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )),
            'silent-responder',
        );

        $locator = new MapReplySenderLocator(['replies' => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $requestTransport,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()
                    ->withPollInterval(Duration::millis(20))
                    ->withAskPendingTimeout(Duration::millis(100)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(500),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // Never acked — the expired entry was rejected for redelivery, not acked.
        self::assertCount(0, $requestTransport->getAcknowledged());
        self::assertGreaterThanOrEqual(1, count($requestTransport->getRejected()));

        // No reply was published.
        self::assertCount(0, $replySender->sent);
    }
}
