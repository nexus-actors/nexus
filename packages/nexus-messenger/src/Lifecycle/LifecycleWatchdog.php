<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\TaskContext;
use Monadial\Nexus\Messenger\Event\WorkerRecyclingTriggered;
use Monadial\Nexus\Runtime\Duration;
use Throwable;

use function is_int;
use function memory_get_usage;

/**
 * Worker-recycling actor: self-ticks on a fixed interval and triggers a
 * graceful ActorSystem::shutdown() when any LifecycleThresholds limit is
 * reached (memory budget, uptime, or cumulative MessagesProcessed count as
 * reported by ReceiverActor instances).
 *
 * Long-running PHP processes leak; the standard defense is "exit gracefully
 * after N messages / X memory / T uptime and let the process manager
 * (systemd, supervisor, k8s) restart". This replaces messenger:consume's
 * --limit/--memory-limit/--time-limit flags with a plain supervised actor —
 * no symfony/console involved. Uptime is measured with second precision via
 * the system clock.
 *
 * Example:
 * ```php
 * $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
 *     $system,
 *     LifecycleThresholds::none()->withMessageLimit(10_000),
 * )), 'watchdog');
 * ```
 *
 * @psalm-api
 */
final readonly class LifecycleWatchdog
{
    private function __construct()
    {
    }

    /**
     * @param Closure(): int|null $memoryProbe returns current usage in bytes; defaults to memory_get_usage(true)
     * @return Behavior<object>
     * @psalm-suppress InvalidArgument Psalm cannot infer U through nested setup→withState generic closures
     */
    public static function create(
        ActorSystem $system,
        LifecycleThresholds $thresholds,
        ?Duration $checkInterval = null,
        ?Duration $shutdownTimeout = null,
        ?Closure $memoryProbe = null,
    ): Behavior {
        $interval = $checkInterval ?? Duration::seconds(5);
        $timeout = $shutdownTimeout ?? Duration::seconds(10);
        $probe = $memoryProbe ?? static fn(): int => memory_get_usage(true);

        return Behavior::setup(
            static function (ActorContext $ctx) use ($system, $thresholds, $interval, $timeout, $probe): Behavior {
                $startedAt = $system->clock()->now()->getTimestamp();
                $tick = $ctx->scheduleRepeatedly($interval, $interval, new Tick());

                return Behavior::withState(
                    0,
                    static function (ActorContext $ctx, object $message, mixed $processed) use ($system, $thresholds, $timeout, $probe, $startedAt, $tick): BehaviorWithState {
                        $count = is_int($processed)
                            ? $processed
                            : 0;

                        if ($message instanceof MessagesProcessed) {
                            return BehaviorWithState::next($count + $message->count);
                        }

                        if (!$message instanceof Tick) {
                            return BehaviorWithState::same();
                        }

                        $uptime = Duration::seconds($system->clock()->now()->getTimestamp() - $startedAt);
                        $reason = $thresholds->breachReason($probe(), $uptime, $count);

                        if ($reason !== null) {
                            $ctx->log()->info('LifecycleWatchdog triggering graceful shutdown', ['reason' => $reason]);
                            self::swallow(
                                static fn(): mixed => $system->eventDispatcher()->dispatch(
                                    new WorkerRecyclingTriggered($reason),
                                ),
                            );
                            self::swallow(static fn(): mixed => $ctx->meter()->counter(
                                'nexus.messenger.worker.recycles',
                                '{recycle}',
                                'Worker recycles triggered by lifecycle thresholds',
                            )->add(1));
                            self::swallow(static fn(): mixed => $tick->cancel());
                            $ctx->spawnTask(static function (TaskContext $_task) use ($system, $timeout): void {
                                $system->shutdown($timeout);
                            });

                            return BehaviorWithState::stopped();
                        }

                        return BehaviorWithState::same();
                    },
                );
            },
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function swallow(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break message flow.
        }
    }
}
