<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SwooleLifecycleSignalsTest extends TestCase
{
    /**
     * Test: PreStart signal is delivered when an actor starts.
     */
    public function testPreStartIsDelivered(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<class-string<Signal>> $signals */
        $signals = [];

        $system = ActorSystem::create('lifecycle-prestart-test', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signals): Behavior {
            $signals[] = $signal::class;

            return Behavior::same();
        });

        $system->spawn(Props::fromBehavior($behavior), 'lifecycle-actor');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        // PreStart is delivered during start(), before run()
        // PostStop is delivered during shutdown
        self::assertContains(PreStart::class, $signals);
    }

    /**
     * Test: PostStop signal is delivered when an actor is stopped.
     */
    public function testPostStopIsDelivered(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<class-string<Signal>> $signals */
        $signals = [];

        $system = ActorSystem::create('lifecycle-poststop-test', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signals): Behavior {
            $signals[] = $signal::class;

            return Behavior::same();
        });

        $system->spawn(Props::fromBehavior($behavior), 'lifecycle-actor');

        // Schedule shutdown which stops all actors
        $runtime->scheduleOnce(Duration::millis(100), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertContains(PostStop::class, $signals);
    }

    /**
     * Test: Both PreStart and PostStop are delivered in the correct order.
     */
    public function testPreStartAndPostStopOrder(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<class-string<Signal>> $signals */
        $signals = [];

        $system = ActorSystem::create('lifecycle-order-test', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signals): Behavior {
            $signals[] = $signal::class;

            return Behavior::same();
        });

        $system->spawn(Props::fromBehavior($behavior), 'lifecycle-actor');

        $runtime->scheduleOnce(Duration::millis(100), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        // PreStart should come before PostStop
        $preStartIndex = array_search(PreStart::class, $signals, true);
        $postStopIndex = array_search(PostStop::class, $signals, true);

        self::assertNotFalse($preStartIndex, 'PreStart signal should have been delivered');
        self::assertNotFalse($postStopIndex, 'PostStop signal should have been delivered');
        self::assertLessThan($postStopIndex, $preStartIndex, 'PreStart should be delivered before PostStop');
    }

    /**
     * Test: Explicitly stopping an actor delivers PostStop.
     */
    public function testExplicitStopDeliversPostStop(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<class-string<Signal>> $signals */
        $signals = [];

        $system = ActorSystem::create('explicit-stop-test', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signals): Behavior {
            $signals[] = $signal::class;

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'stoppable');

        // Schedule explicit stop inside Co\run (stop() calls tell() which needs coroutine context)
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($system, $ref): void {
            $system->stop($ref);
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertContains(PostStop::class, $signals);
    }

    /**
     * Test: Actor that returns Behavior::stopped() gets PostStop signal.
     */
    public function testSelfStopDeliversPostStop(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<class-string<Signal>> $signals */
        $signals = [];

        $system = ActorSystem::create('self-stop-test', $runtime);

        /** @var Behavior<object> $stopOnFirstMessage */
        $stopOnFirstMessage = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            // Stop self on any message
            return Behavior::stopped();
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signals): Behavior {
            $signals[] = $signal::class;

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($stopOnFirstMessage), 'self-stopper');

        // Schedule message sending inside Co\run (tell() needs coroutine context)
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($ref): void {
            $ref->tell(new stdClass());
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertContains(PreStart::class, $signals);
        self::assertContains(PostStop::class, $signals);
    }
}
