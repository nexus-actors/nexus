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
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Increment;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Performance benchmarks for the Swoole runtime.
 *
 * Non-functional requirements:
 *   - Message throughput (Swoole): >100K msg/sec per worker
 *   - Actor creation: <50μs per actor
 *   - Message latency (same process): <10μs p99
 *
 * Key fix: actors self-shutdown when processing completes. Swoole tell() requires
 * coroutine context, so messages are sent via scheduleOnce(Duration::millis(1)).
 *
 * Run: docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter=Swoole
 */
final class SwoolePerformanceTest extends TestCase
{
    /**
     * Measure message throughput: how many messages per second a single actor processes.
     *
     * Target: >100K msg/sec (Swoole)
     */
    public function testMessageThroughput(): void
    {
        $messageCount = 100_000;

        $metrics = Benchmark::measure(
            "Swoole: {$messageCount} messages to single actor",
            $messageCount,
            static function () use ($messageCount): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-throughput', $runtime);

                $processed = 0;

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processed, $messageCount, $system): Behavior {
                        $processed++;

                        if ($processed >= $messageCount) {
                            $system->shutdown(Duration::millis(100));
                        }

                        return Behavior::same();
                    },
                );

                $ref = $system->spawn(Props::fromBehavior($behavior), 'sink');

                // tell() requires coroutine context in Swoole (Channel::push)
                $runtime->scheduleOnce(Duration::millis(1), static function () use ($ref, $messageCount): void {
                    for ($i = 0; $i < $messageCount; $i++) {
                        $ref->tell(new stdClass());
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure raw message dispatch rate: how fast tell() can enqueue messages.
     * Isolates the enqueue path (Channel::push + Envelope creation) from processing.
     * Measured inside Co\run since Channel::push requires coroutine context.
     */
    public function testMessageDispatchRate(): void
    {
        $messageCount = 100_000;

        $dispatchElapsedMs = 0.0;
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-dispatch', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $ref = $system->spawn(Props::fromBehavior($behavior), 'sink');

        // Measure tell() dispatch rate inside coroutine context
        $runtime->scheduleOnce(
            Duration::millis(1),
            static function () use ($ref, $messageCount, &$dispatchElapsedMs, $system): void {
                $msg = new stdClass();
                $start = hrtime(true);

                for ($i = 0; $i < $messageCount; $i++) {
                    $ref->tell($msg);
                }

                $dispatchElapsedMs = (hrtime(true) - $start) / 1_000_000;

                // Schedule shutdown after dispatch measurement is captured
                $system->shutdown(Duration::millis(100));
            },
        );

        $runtime->run();

        $opsPerSecond = $dispatchElapsedMs > 0
            ? $messageCount / $dispatchElapsedMs * 1000
            : 0;

        fwrite(STDERR, sprintf(
            "\n  [Swoole: dispatch %s messages] %.1fms (%.0f dispatch/sec)\n",
            number_format($messageCount),
            $dispatchElapsedMs,
            $opsPerSecond,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
    }

    /**
     * Measure actor spawn rate: how many actors can be spawned per second.
     * Measures only spawn() calls (before run).
     *
     * Target: <50μs per actor (>20K actors/sec)
     */
    public function testActorSpawnRate(): void
    {
        $actorCount = 1_000;

        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-spawn', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $start = hrtime(true);

        for ($i = 0; $i < $actorCount; $i++) {
            $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $opsPerSecond = $elapsedMs > 0
            ? $actorCount / $elapsedMs * 1000
            : 0;
        $usPerActor = $elapsedMs * 1000 / $actorCount;

        // Clean up — PoisonPills require coroutine context
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($system): void {
            $system->shutdown(Duration::millis(100));
        });

        $runtime->run();

        fwrite(STDERR, sprintf(
            "\n  [Swoole: spawn %s actors] %.1fms (%.0f ops/sec, %.1fμs/actor)\n",
            number_format($actorCount),
            $elapsedMs,
            $opsPerSecond,
            $usPerActor,
        ));

        self::assertGreaterThan(0, $opsPerSecond);
    }

    /**
     * Measure actor killing: time to stop actors via PoisonPill.
     * Actors process PoisonPill and terminate; Co\run() exits when all coroutines complete.
     */
    public function testActorKillRate(): void
    {
        $actorCount = 500;

        $metrics = Benchmark::measure(
            "Swoole: kill {$actorCount} actors via PoisonPill",
            $actorCount,
            static function () use ($actorCount): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-kill', $runtime);

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });

                $refs = [];

                for ($i = 0; $i < $actorCount; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
                }

                // Kill all actors — PoisonPill requires coroutine context
                $runtime->scheduleOnce(Duration::millis(1), static function () use ($refs): void {
                    foreach ($refs as $ref) {
                        $ref->tell(new PoisonPill());
                    }
                });

                // Co\run() exits when all coroutines terminate and no timers remain
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure burst message dispatch: send N messages at once, measure total delivery time.
     * Actor self-shutdowns when all messages processed.
     */
    public function testBurstMessageDispatch(): void
    {
        $burstSize = 50_000;

        $metrics = Benchmark::measure(
            "Swoole: burst of {$burstSize} messages",
            $burstSize,
            static function () use ($burstSize): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-burst', $runtime);

                $processed = 0;

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processed, $burstSize, $system): Behavior {
                        $processed++;

                        if ($processed >= $burstSize) {
                            $system->shutdown(Duration::millis(100));
                        }

                        return Behavior::same();
                    },
                );

                $ref = $system->spawn(Props::fromBehavior($behavior), 'burst-sink');

                $runtime->scheduleOnce(Duration::millis(1), static function () use ($ref, $burstSize): void {
                    $msg = new stdClass();

                    for ($i = 0; $i < $burstSize; $i++) {
                        $ref->tell($msg);
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure memory per actor: how much memory each spawned actor consumes.
     * Uses memory_get_usage(false) for actual PHP object memory, not OS page allocations.
     */
    public function testMemoryPerActor(): void
    {
        $actorCount = 1_000;

        gc_collect_cycles();
        $memBefore = memory_get_usage(false);

        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-memory', $runtime);

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        for ($i = 0; $i < $actorCount; $i++) {
            $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
        }

        $memAfter = memory_get_usage(false);
        $bytesPerActor = ($memAfter - $memBefore) / $actorCount;

        // Clean up
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($system): void {
            $system->shutdown(Duration::millis(100));
        });

        $runtime->run();

        fwrite(STDERR, sprintf(
            "\n  [Swoole: memory per actor] %d actors: %.0f bytes/actor (total delta: %s)\n",
            $actorCount,
            $bytesPerActor,
            PerformanceMetrics::formatBytes($memAfter - $memBefore),
        ));

        // Sanity: each actor should use < 100KB
        self::assertLessThan(100_000, $bytesPerActor);
        // Should be non-zero with proper measurement
        self::assertGreaterThan(0, $bytesPerActor);
    }

    /**
     * Measure stateful actor throughput with state transitions.
     * Actor self-shutdowns when all transitions complete.
     */
    public function testStatefulActorThroughput(): void
    {
        $messageCount = 100_000;

        $metrics = Benchmark::measure(
            "Swoole: {$messageCount} stateful transitions",
            $messageCount,
            static function () use ($messageCount): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-stateful', $runtime);

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::withState(
                    0,
                    static function (ActorContext $ctx, object $msg, int $count) use ($messageCount, $system): BehaviorWithState {
                        $next = $count + 1;

                        if ($next >= $messageCount) {
                            $system->shutdown(Duration::millis(100));
                        }

                        return BehaviorWithState::next($next);
                    },
                );

                $ref = $system->spawn(Props::fromBehavior($behavior), 'counter');

                $runtime->scheduleOnce(Duration::millis(1), static function () use ($ref, $messageCount): void {
                    for ($i = 0; $i < $messageCount; $i++) {
                        $ref->tell(new Increment());
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure fan-out: one sender distributes messages to many actors.
     * Shared counter across all sinks triggers shutdown when total reached.
     */
    public function testFanOutThroughput(): void
    {
        $fanOut = 100;
        $messagesPerActor = 100;
        $totalMessages = $fanOut * $messagesPerActor;

        $metrics = Benchmark::measure(
            "Swoole: fan-out {$fanOut} actors x {$messagesPerActor} msgs",
            $totalMessages,
            static function () use ($fanOut, $messagesPerActor, $totalMessages): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-fanout', $runtime);

                $totalReceived = 0;

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$totalReceived, $totalMessages, $system): Behavior {
                        $totalReceived++;

                        if ($totalReceived >= $totalMessages) {
                            $system->shutdown(Duration::millis(100));
                        }

                        return Behavior::same();
                    },
                );

                $refs = [];

                for ($i = 0; $i < $fanOut; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "worker-{$i}");
                }

                $runtime->scheduleOnce(Duration::millis(1), static function () use ($refs, $messagesPerActor): void {
                    for ($round = 0; $round < $messagesPerActor; $round++) {
                        foreach ($refs as $ref) {
                            $ref->tell(new stdClass());
                        }
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure ping-pong latency: inter-actor communication round trips.
     * Pinger triggers shutdown when all rounds complete.
     *
     * This test should reveal Swoole's scheduling advantage: Swoole coroutines
     * wake immediately when Channel data arrives (epoll), vs Fiber's usleep(100)
     * polling between tick() cycles.
     *
     * Target: <10μs p99 same-process latency
     */
    public function testPingPongLatency(): void
    {
        $rounds = 5_000;

        $metrics = Benchmark::measure(
            "Swoole: {$rounds} ping-pong round trips",
            $rounds,
            static function () use ($rounds): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-latency', $runtime);
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
                    static function (ActorContext $ctx, object $msg) use (&$completed, $pongerRef, $rounds, $system): Behavior {
                        $completed++;

                        if ($completed >= $rounds) {
                            $system->shutdown(Duration::millis(100));
                        } else {
                            $pongerRef->tell((object) ['replyTo' => $ctx->self()]);
                        }

                        return Behavior::same();
                    },
                );

                $pingerRef = $system->spawn(Props::fromBehavior($pingBehavior), 'pinger');

                // Kick off the first ping inside coroutine context
                $runtime->scheduleOnce(Duration::millis(1), static function () use ($pongerRef, $pingerRef): void {
                    $pongerRef->tell((object) ['replyTo' => $pingerRef]);
                });

                $runtime->run();
            },
        );

        $usPerRoundTrip = $metrics->elapsedMs * 1000 / $rounds;

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        fwrite(STDERR, sprintf("    → %.1fμs per round trip\n", $usPerRoundTrip));
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure multi-dispatch: single actor sends to multiple targets in one message handler.
     * Shared counter across sinks triggers shutdown when all messages received.
     */
    public function testMultiDispatchFromSingleActor(): void
    {
        $targetCount = 50;
        $rounds = 100;
        $totalMessages = $targetCount * $rounds;

        $metrics = Benchmark::measure(
            "Swoole: multi-dispatch {$targetCount} targets x {$rounds} rounds",
            $totalMessages,
            static function () use ($targetCount, $rounds, $totalMessages): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-multi', $runtime);

                $received = 0;

                /** @var Behavior<object> $sinkBehavior */
                $sinkBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$received, $totalMessages, $system): Behavior {
                        $received++;

                        if ($received >= $totalMessages) {
                            $system->shutdown(Duration::millis(100));
                        }

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

                $runtime->scheduleOnce(Duration::millis(1), static function () use ($dispatcher, $rounds): void {
                    for ($round = 0; $round < $rounds; $round++) {
                        $dispatcher->tell(new stdClass());
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure spawn-then-kill cycle: create and stop actors in rapid succession.
     * Co\run() exits when all coroutines terminate and no timers remain.
     */
    public function testSpawnKillCycle(): void
    {
        $cycles = 500;

        $metrics = Benchmark::measure(
            "Swoole: {$cycles} spawn-kill cycles",
            $cycles,
            static function () use ($cycles): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-spawn-kill', $runtime);

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    return Behavior::same();
                });

                $refs = [];

                for ($i = 0; $i < $cycles; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "ephemeral-{$i}");
                }

                // stop() calls tell(PoisonPill) which requires coroutine context
                $runtime->scheduleOnce(Duration::millis(1), static function () use ($system, $refs): void {
                    foreach ($refs as $ref) {
                        $system->stop($ref);
                    }
                });

                // Co\run() exits when all coroutines terminate
                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }

    /**
     * Measure async dispatch: concurrent actors processing in parallel.
     * Verifies that Swoole processes messages across multiple actors concurrently
     * via coroutine scheduling, not sequentially like Fiber's round-robin tick().
     */
    public function testAsyncDispatchVerification(): void
    {
        $actorCount = 10;
        $messagesPerActor = 10_000;
        $totalMessages = $actorCount * $messagesPerActor;

        $metrics = Benchmark::measure(
            "Swoole: {$actorCount} concurrent actors x {$messagesPerActor} msgs",
            $totalMessages,
            static function () use ($actorCount, $messagesPerActor, $totalMessages): void {
                $runtime = new SwooleRuntime();
                $system = ActorSystem::create('swoole-concurrent', $runtime);

                $totalProcessed = 0;

                /** @var Behavior<object> $behavior */
                $behavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$totalProcessed, $totalMessages, $system): Behavior {
                        $totalProcessed++;

                        if ($totalProcessed >= $totalMessages) {
                            $system->shutdown(Duration::millis(100));
                        }

                        return Behavior::same();
                    },
                );

                $refs = [];

                for ($i = 0; $i < $actorCount; $i++) {
                    $refs[] = $system->spawn(Props::fromBehavior($behavior), "actor-{$i}");
                }

                // Send messages to all actors
                $runtime->scheduleOnce(Duration::millis(1), static function () use ($refs, $messagesPerActor): void {
                    foreach ($refs as $ref) {
                        for ($i = 0; $i < $messagesPerActor; $i++) {
                            $ref->tell(new stdClass());
                        }
                    }
                });

                $runtime->run();
            },
        );

        fwrite(STDERR, "\n  " . $metrics->report() . "\n");
        self::assertGreaterThan(0, $metrics->opsPerSecond);
    }
}
