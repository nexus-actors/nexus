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
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Pong;
use Monadial\Nexus\Tests\Integration\Messenger\Support\FloodReceiver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * REL-008: the responder-side pending-ask map must stay bounded so a producer
 * flooding ask envelopes cannot exhaust consumer memory.
 */
#[CoversClass(ReceiverActor::class)]
final class PendingAskBoundTest extends TestCase
{
    /**
     * Flood the receiver with more distinct asks than the configured cap while the
     * responder never replies (so nothing frees a slot during the run). The pending
     * gauge must never exceed the cap, and every ask beyond the cap must be shed —
     * rejected for redelivery — with an accurate shed counter.
     */
    #[Test]
    public function floodingAsksAboveTheCapIsDeterministicallyShed(): void
    {
        $cap = 5;
        $flood = 20;

        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $system = ActorSystem::create('ask-flood', $runtime, observability: $observability);
        // Do not redeliver shed asks, so the shed count is exact rather than growing each tick.
        $receiver = new FloodReceiver(redeliverRejected: false);
        $replySender = new RecordingSender();

        for ($i = 0; $i < $flood; $i++) {
            $receiver->push(
                (new Envelope(new Ping("req-{$i}")))
                    ->with(new CorrelationIdStamp("corr-{$i}"))
                    ->with(new ReplyToStamp('replies')),
            );
        }

        // Silent responder: holds every admitted ask pending, never replies, never frees a slot.
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )),
            'silent-responder',
        );

        $locator = new MapReplySenderLocator(['replies' => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $receiver,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()
                    ->withPollInterval(Duration::millis(20))
                    ->withAskPendingTimeout(Duration::seconds(30))
                    ->withMaxPendingAsks($cap),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(200),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // The bound held: the gauge peaked at exactly the cap — the first cap asks were
        // admitted, then shedding kept the map from growing further.
        self::assertSame(
            $cap,
            $observability->meter->gaugePeak('nexus.messenger.asks.pending'),
            'Pending-ask gauge must peak at the cap, never above it',
        );

        // Exactly the asks over the cap were shed for redelivery — deterministic, counted.
        self::assertSame(
            $flood - $cap,
            $observability->meter->sum('nexus.messenger.asks.shed'),
            'Exactly the asks over the cap must be shed',
        );

        // No responder ever replied, so nothing was acked and no reply was published.
        self::assertSame([], $receiver->acked);
        self::assertCount(0, $replySender->sent);
    }

    /**
     * Once a slot frees (an ask is answered and acked), a previously shed ask is admitted
     * on redelivery — shedding is load-shedding, not permanent rejection.
     */
    #[Test]
    public function shedAsksAreAdmittedOnceSlotsFree(): void
    {
        $cap = 2;

        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $system = ActorSystem::create('ask-drain', $runtime, observability: $observability);
        $receiver = new FloodReceiver(redeliverRejected: true);
        $replySender = new RecordingSender();

        for ($i = 0; $i < 4; $i++) {
            $receiver->push(
                (new Envelope(new Ping("req-{$i}")))
                    ->with(new CorrelationIdStamp("corr-{$i}"))
                    ->with(new ReplyToStamp('replies')),
            );
        }

        // Responder replies immediately, freeing each slot so shed asks get admitted
        // on a later redelivery.
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof Ping) {
                        $ctx->sender()?->tell(new Pong('ok'));
                    }

                    return Behavior::same();
                },
            )),
            'fast-responder',
        );

        $locator = new MapReplySenderLocator(['replies' => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $receiver,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()
                    ->withPollInterval(Duration::millis(20))
                    ->withMaxPendingAsks($cap),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(800),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // Every ask — including the ones shed on the first pass — was ultimately admitted
        // and answered: shedding is load-shedding, not permanent rejection.
        self::assertCount(4, $replySender->sent);
        // More asks were acked than the cap, proving freed slots admitted previously-shed asks.
        self::assertGreaterThan(
            $cap,
            count($receiver->acked),
            'Freed slots must admit previously shed asks (acked beyond the cap)',
        );
        // The bound held throughout: the gauge never exceeded the cap while draining the backlog.
        self::assertLessThanOrEqual(
            $cap,
            $observability->meter->gaugePeak('nexus.messenger.asks.pending'),
            'The gauge must never exceed the cap even while draining the backlog',
        );
    }

    /**
     * On stop, still-pending asks are rejected for redelivery (not silently abandoned)
     * and the pending gauge returns to zero — cleanup on disconnect.
     */
    #[Test]
    public function pendingAsksAreRejectedAndGaugeZeroedOnStop(): void
    {
        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $system = ActorSystem::create('ask-stop', $runtime, observability: $observability);
        $receiver = new FloodReceiver(redeliverRejected: false);
        $replySender = new RecordingSender();

        for ($i = 0; $i < 3; $i++) {
            $receiver->push(
                (new Envelope(new Ping("req-{$i}")))
                    ->with(new CorrelationIdStamp("corr-{$i}"))
                    ->with(new ReplyToStamp('replies')),
            );
        }

        // Silent responder: the three asks stay pending until the receiver stops.
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            )),
            'silent-responder',
        );

        $locator = new MapReplySenderLocator(['replies' => $replySender]);

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $receiver,
                new MapMessageRouter(Route::to(Ping::class, $target)),
                ReceiverActorConfig::default()
                    ->withPollInterval(Duration::millis(20))
                    ->withAskPendingTimeout(Duration::seconds(30)),
                null,
                null,
                null,
                null,
                $locator,
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(150),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // The three pending asks were rejected for redelivery on stop; the gauge is back to zero.
        self::assertCount(3, $receiver->rejected);
        self::assertSame([], $receiver->acked);
        self::assertSame(0, $observability->meter->gaugeValue('nexus.messenger.asks.pending'));
    }
}
