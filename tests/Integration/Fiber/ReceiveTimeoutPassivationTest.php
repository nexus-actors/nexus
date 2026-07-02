<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ReceiveTimeoutPassivationTest extends TestCase
{
    #[Test]
    public function receiveTimeoutFiresAfterIdle(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime);

        /** @var list<string> $signalsSeen */
        $signalsSeen = [];
        $postStopFired = false;

        $behavior = Behavior::setup(static function (ActorContext $ctx) use (&$signalsSeen, &$postStopFired): Behavior {
            $ctx->setReceiveTimeout(Duration::millis(100));

            return Behavior::receive(static fn(ActorContext $c, object $msg): Behavior => Behavior::same())
                ->onSignal(
                    static function (ActorContext $c, object $signal) use (&$signalsSeen, &$postStopFired): Behavior {
                        if ($signal instanceof ReceiveTimeout) {
                            $signalsSeen[] = 'receive-timeout';

                            return Behavior::stopped();
                        }

                        if ($signal instanceof PostStop) {
                            $postStopFired = true;
                        }

                        return Behavior::same();
                    },
                );
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'actor');

        // Send one message to verify alive baseline, then go idle.
        $runtime->scheduleOnce(Duration::millis(10), static fn() => $ref->tell(new stdClass()));

        // After 200ms (well past the 100ms timeout from the last message at 10ms),
        // the actor should have been stopped.
        /** @var bool|null $aliveBeforeTimeout */
        $aliveBeforeTimeout = null;
        /** @var bool|null $aliveAfterTimeout */
        $aliveAfterTimeout = null;

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($ref, &$aliveBeforeTimeout): void {
            $aliveBeforeTimeout = $ref->isAlive();
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($ref, &$aliveAfterTimeout): void {
            $aliveAfterTimeout = $ref->isAlive();
        });

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertTrue($aliveBeforeTimeout, 'actor should be alive before timeout');
        self::assertFalse($aliveAfterTimeout, 'actor should be passivated after timeout');
        self::assertContains('receive-timeout', $signalsSeen);
        self::assertTrue($postStopFired, 'PostStop should run as part of passivation cleanup');
    }

    #[Test]
    public function userMessageDelaysReceiveTimeout(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime);

        // Arm the timeout from inside the first message handler so the timer ticks
        // from a known point within the event loop — not from spawn() wall-clock time.
        $timeoutArmed = false;

        $behavior = Behavior::receive(static function (ActorContext $c, object $msg) use (&$timeoutArmed): Behavior {
            if (!$timeoutArmed) {
                // 200ms idle timeout; messages arrive every 80ms → well under threshold.
                $c->setReceiveTimeout(Duration::millis(200));
                $timeoutArmed = true;
            }

            return Behavior::same();
        })->onSignal(static function (ActorContext $c, object $signal): Behavior {
            if ($signal instanceof ReceiveTimeout) {
                return Behavior::stopped();
            }

            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'actor-keep-alive');

        // First message at 20ms arms the timer. Subsequent messages at 100, 180, 260,
        // 340ms each arrive within 80ms of the previous → timeout resets every time.
        $runtime->scheduleOnce(Duration::millis(20), static fn() => $ref->tell(new stdClass()));

        for ($i = 0; $i < 4; $i++) {
            $runtime->scheduleOnce(
                Duration::millis(100 + 80 * $i),
                static fn() => $ref->tell(new stdClass()),
            );
        }

        // Check alive state at two points that bracket whether the timer was reset:
        //   250ms — last message was at 260ms; timer armed at 20ms would have expired
        //           by now (200ms timeout) if NOT reset, but messages keep resetting it.
        //   450ms — last message was at 340ms; with a reset timer the timeout fires at
        //           540ms, so the actor must still be alive here.
        /** @var bool|null $aliveAt250 */
        $aliveAt250 = null;
        /** @var bool|null $aliveAt450 */
        $aliveAt450 = null;

        $runtime->scheduleOnce(Duration::millis(250), static function () use ($ref, &$aliveAt250): void {
            $aliveAt250 = $ref->isAlive();
        });

        $runtime->scheduleOnce(Duration::millis(450), static function () use ($ref, &$aliveAt450): void {
            $aliveAt450 = $ref->isAlive();
        });

        $runtime->scheduleOnce(Duration::millis(700), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // The actor must be alive at 250ms: if the timer were NOT reset on each message,
        // it would have fired 200ms after the first message (at ~220ms).
        self::assertTrue($aliveAt250, 'actor should be alive at 250ms — timer must be resetting on each message');
        // At 450ms (110ms after last message at 340ms) the 200ms idle window has not elapsed.
        self::assertTrue($aliveAt450, 'actor should still be alive 110ms after last message');
    }
}
