<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Increment;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Performance benchmarks for the Fiber runtime.
 *
 * Non-functional requirements:
 *   - Message throughput (Fiber): >50K msg/sec
 *   - Actor creation: <50μs per actor
 *   - Message latency (same process): <10μs p99
 *
 * Run: vendor/bin/phpunit --testsuite=performance --filter=Fiber
 */
final class FiberPerformanceTest extends TestCase
{
    /**
     * Measure message throughput: how many messages per second a single actor processes.
     *
     * Target: >50K msg/sec (Fiber)
     */
    public function testMessageThroughput(): void
    {
        $messageCount = 10_000;

        $metrics = Benchmark::measure(
            "Fiber: {$messageCount} messages to single actor",
            $messageCount,
            static function () use ($messageCount): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-throughput', $runtime);
    
                $processed = 0;
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processed): Behavior {
                        $processed++;
        
                    return Behavior::same();
                    },
                );
    
                $ref = $system->spawn(Props::fromBehavior($behavior), 'sink');
    
                for ($i = 0; $i < $messageCount; $i++) {
                    $ref->tell(new stdClass());
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure actor spawn rate: how many actors can be spawned per second.
     *
     * Target: <50μs per actor (>20K actors/sec)
     */
    public function testActorSpawnRate(): void
    {
        $actorCount = 1_000;

        $metrics = Benchmark::measure(
            "Fiber: spawn {$actorCount} actors",
            $actorCount,
            static function () use ($actorCount): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-spawn', $runtime);
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });
    
                for ($i = 0; $i < $actorCount; $i++) {
                    $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure actor killing: time to stop actors via PoisonPill.
     */
    public function testActorKillRate(): void
    {
        $actorCount = 100;

        $metrics = Benchmark::measure(
            "Fiber: kill {$actorCount} actors via PoisonPill",
            $actorCount,
            static function () use ($actorCount): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-kill', $runtime);
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });
    
                $refs = [];
    
                for ($i = 0; $i < $actorCount; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
                }
    
                // Kill all actors via PoisonPill
            foreach ($refs as $ref) {
                    $ref->tell(new PoisonPill());
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure burst message dispatch: send N messages at once, measure total delivery time.
     */
    public function testBurstMessageDispatch(): void
    {
        $burstSize = 5_000;

        $metrics = Benchmark::measure(
            "Fiber: burst of {$burstSize} messages",
            $burstSize,
            static function () use ($burstSize): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-burst', $runtime);
    
                $processed = 0;
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processed): Behavior {
                        $processed++;
        
                    return Behavior::same();
                    },
                );
    
                $ref = $system->spawn(Props::fromBehavior($behavior), 'burst-sink');
    
                // Send all messages in a tight burst
            $msg = new stdClass();
    
                for ($i = 0; $i < $burstSize; $i++) {
                    $ref->tell($msg);
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure memory per actor: how much memory each spawned actor consumes.
     */
    public function testMemoryPerActor(): void
    {
        $actorCount = 1_000;

        gc_collect_cycles();
        $memBefore = memory_get_usage(true);

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('fiber-memory', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        for ($i = 0; $i < $actorCount; $i++) {
            $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
        }

        $memAfter = memory_get_usage(true);
        $bytesPerActor = ($memAfter - $memBefore) / $actorCount;

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        fwrite(STDERR, sprintf(
            "\n  [Fiber: memory per actor] %d actors: %.0f bytes/actor (total delta: %s)\n",
            $actorCount,
            $bytesPerActor,
            PerformanceMetrics::formatBytes($memAfter - $memBefore),
        ));

        // Sanity: each actor should use < 100KB
        self::assertLessThan(100_000, $bytesPerActor);
    }

    /**
     * Measure stateful actor throughput with state transitions.
     */
    public function testStatefulActorThroughput(): void
    {
        $messageCount = 10_000;

        $metrics = Benchmark::measure(
            "Fiber: {$messageCount} stateful transitions",
            $messageCount,
            static function () use ($messageCount): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-stateful', $runtime);
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::withState(
                    0,
                    static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                        return BehaviorWithState::next($count + 1);
                    },
                );
    
                $ref = $system->spawn(Props::fromBehavior($behavior), 'counter');
    
                for ($i = 0; $i < $messageCount; $i++) {
                    $ref->tell(new Increment());
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure fan-out: one sender distributes messages to many actors.
     */
    public function testFanOutThroughput(): void
    {
        $fanOut = 100;
        $messagesPerActor = 100;
        $totalMessages = $fanOut * $messagesPerActor;

        $metrics = Benchmark::measure(
            "Fiber: fan-out {$fanOut} actors x {$messagesPerActor} msgs",
            $totalMessages,
            static function () use ($fanOut, $messagesPerActor): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-fanout', $runtime);
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });
    
                $refs = [];
    
                for ($i = 0; $i < $fanOut; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "worker-{$i}");
                }
    
                for ($round = 0; $round < $messagesPerActor; $round++) {
                    foreach ($refs as $ref) {
                        $ref->tell(new stdClass());
                    }
                }
    
                $runtime->scheduleOnce(Duration::millis(1000), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure ping-pong latency: inter-actor communication round trips.
     *
     * Target: <10μs p99 same-process latency
     */
    public function testPingPongLatency(): void
    {
        $rounds = 1_000;

        $metrics = Benchmark::measure(
            "Fiber: {$rounds} ping-pong round trips",
            $rounds,
            static function () use ($rounds): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-latency', $runtime);
                $completed = 0;
    
                /** @var Behavior<object> $pongBehavior */
                $pongBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if (isset($msg->replyTo)) {
                        $msg->replyTo->tell(new stdClass());
                    }
    
                    return Behavior::same();
                });
    
                $pongerRef = $system->spawn(Props::fromBehavior($pongBehavior), 'ponger');
    
                /** @var Behavior<object> $pingBehavior */
                $pingBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$completed, $pongerRef, $rounds): Behavior {
                        $completed++;
        
                    if ($completed < $rounds) {
                            $pongerRef->tell((object) ['replyTo' => $ctx->self()]);
                        }
        
                    return Behavior::same();
                    },
                );
    
                $pingerRef = $system->spawn(Props::fromBehavior($pingBehavior), 'pinger');
    
                // Kick off the first ping
            $pongerRef->tell((object) ['replyTo' => $pingerRef]);
    
                $runtime->scheduleOnce(Duration::millis(2000), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure multi-dispatch: single actor sends to multiple targets in one message handler.
     */
    public function testMultiDispatchFromSingleActor(): void
    {
        $targetCount = 50;
        $rounds = 100;
        $totalMessages = $targetCount * $rounds;

        $metrics = Benchmark::measure(
            "Fiber: multi-dispatch {$targetCount} targets x {$rounds} rounds",
            $totalMessages,
            static function () use ($targetCount, $rounds): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-multi', $runtime);
    
                $received = 0;
    
                /** @var Behavior<object> $sinkBehavior */
                $sinkBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                        $received++;
        
                    return Behavior::same();
                    },
                );
    
                $targets = [];
    
                for ($i = 0; $i < $targetCount; $i++) {
                    $targets[] = $system->spawn(Props::fromBehavior($sinkBehavior), "target-{$i}");
                }
    
                // Dispatcher actor: receives a "go" message and dispatches to all targets
            /** @var Behavior<object> $dispatcherBehavior */
                $dispatcherBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use ($targets): Behavior {
                        foreach ($targets as $target) {
                            $target->tell(new stdClass());
                        }
        
                    return Behavior::same();
                    },
                );
    
                $dispatcher = $system->spawn(Props::fromBehavior($dispatcherBehavior), 'dispatcher');
    
                for ($round = 0; $round < $rounds; $round++) {
                    $dispatcher->tell(new stdClass());
                }
    
                $runtime->scheduleOnce(Duration::millis(1000), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure spawn-then-kill cycle: create and stop actors in rapid succession.
     */
    public function testSpawnKillCycle(): void
    {
        $cycles = 200;

        $metrics = Benchmark::measure(
            "Fiber: {$cycles} spawn-kill cycles",
            $cycles,
            static function () use ($cycles): void {
                $runtime = new FiberRuntime();
                $system = ActorSystem::create('fiber-spawn-kill', $runtime);
    
                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });
    
                for ($i = 0; $i < $cycles; $i++) {
                    $ref = $system->spawn(Props::fromBehavior($behavior), "ephemeral-{$i}");
                    $system->stop($ref);
                }
    
                $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
                    $system->shutdown(Duration::seconds(1));
                });
    
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }
}
