<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Pong;
use PHPUnit\Framework\TestCase;

final class SwoolePingPongTest extends TestCase
{
    /**
     * Test: Actor A sends Ping to Actor B, Actor B replies with Pong.
     *
     * Flow:
     *   1. Spawn a "ponger" actor that receives Ping and replies with Pong
     *   2. Spawn a probe actor that captures received messages
     *   3. Send Ping(probeRef) to the ponger (inside Co\run via scheduleOnce)
     *   4. Run the runtime
     *   5. Assert probe received Pong
     */
    public function testPingPongRoundTrip(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<object> $captured */
        $captured = [];

        $system = ActorSystem::create('ping-pong-test', $runtime);

        // Ponger actor: receives Ping, sends Pong to replyTo
        /** @var Behavior<object> $pongerBehavior */
        $pongerBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof Ping) {
                $msg->replyTo->tell(new Pong());
            }

            return Behavior::same();
        });

        $pongerRef = $system->spawn(Props::fromBehavior($pongerBehavior), 'ponger');

        // Probe captures messages
        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Schedule message sending inside Co\run (Swoole Channel requires coroutine context)
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($pongerRef, $probeRef): void {
            $pongerRef->tell(new Ping($probeRef));
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(Pong::class, $captured[0]);
    }

    /**
     * Test: Multiple ping-pong rounds.
     */
    public function testMultiplePingPongRounds(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<object> $captured */
        $captured = [];

        $system = ActorSystem::create('multi-ping-pong-test', $runtime);

        /** @var Behavior<object> $pongerBehavior */
        $pongerBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof Ping) {
                $msg->replyTo->tell(new Pong());
            }

            return Behavior::same();
        });

        $pongerRef = $system->spawn(Props::fromBehavior($pongerBehavior), 'ponger');

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Schedule 3 pings inside Co\run
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($pongerRef, $probeRef): void {
            for ($i = 0; $i < 3; $i++) {
                $pongerRef->tell(new Ping($probeRef));
            }
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(3, $captured);

        foreach ($captured as $msg) {
            self::assertInstanceOf(Pong::class, $msg);
        }
    }

    /**
     * Test: Two actors exchange ping-pong in a chain.
     *
     * Pinger sends Ping to Ponger. Ponger replies with Pong.
     * Pinger counts received Pongs.
     */
    public function testPingerPongerInteraction(): void
    {
        $runtime = new SwooleRuntime();

        /** @var list<object> $pongsCaptured */
        $pongsCaptured = [];

        $system = ActorSystem::create('pinger-ponger-test', $runtime);

        // Ponger: replies to Ping with Pong
        /** @var Behavior<object> $pongerBehavior */
        $pongerBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof Ping) {
                $msg->replyTo->tell(new Pong());
            }

            return Behavior::same();
        });

        $pongerRef = $system->spawn(Props::fromBehavior($pongerBehavior), 'ponger');

        // Pinger: captures Pong replies
        /** @var Behavior<object> $pingerBehavior */
        $pingerBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$pongsCaptured): Behavior {
                if ($msg instanceof Pong) {
                    $pongsCaptured[] = $msg;
                }

                return Behavior::same();
            },
        );

        $pingerRef = $system->spawn(Props::fromBehavior($pingerBehavior), 'pinger');

        // Schedule message sending inside Co\run
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($pongerRef, $pingerRef): void {
            $pongerRef->tell(new Ping($pingerRef));
        });

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertCount(1, $pongsCaptured);
        self::assertInstanceOf(Pong::class, $pongsCaptured[0]);
    }
}
