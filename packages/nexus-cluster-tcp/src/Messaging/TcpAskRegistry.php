<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Exception\AskCapacityExceededException;
use Monadial\Nexus\Cluster\Tcp\Exception\PeerUnreachableException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Throwable;

use function count;
use function hrtime;

/**
 * @psalm-api
 *
 * Correlation table for in-flight remote asks. Standalone reimplementation of the bounded,
 * first-reply-wins, per-ask-timeout pattern (no dependency on nexus-messenger).
 *
 * A remote ask registers under its correlation ID, sends a request frame carrying a
 * `replyPath` derived from {@see \Monadial\Nexus\Cluster\NodeAddress::temporaryAskReplyPath()},
 * and awaits the returned {@see Future}. The reply frame routes back by that path and its
 * echoed correlation ID resolves the pending slot. Timeouts are driven by the runtime's
 * scheduler (one RTT budget), mirroring {@see \Monadial\Nexus\Core\Actor\LocalActorRef::ask()}.
 */
final class TcpAskRegistry
{
    /** @var array<string, FutureSlot<object>> */
    private array $pending = [];

    /** @var array<string, int> hrtime(true) values keyed by correlationId */
    private array $startTimes = [];

    /** @var array<string, string> Target node path-prefix keyed by correlationId — lets a peer-down fail its in-flight asks. */
    private array $targets = [];

    private ?Counter $asksResolved = null;

    private ?Counter $asksTimedOut = null;

    private ?Histogram $askDuration = null;

    private ?ObservableGauge $pendingGauge = null;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly int $maxPending = 10_000,
        private readonly Meter $meter = new NoopMeter(),
    ) {}

    /**
     * Register a pending ask and schedule its timeout. The returned future resolves when a
     * matching reply arrives or fails with {@see AskTimeoutException} once `$timeout` elapses.
     *
     * @return Future<object>
     *
     * @throws AskCapacityExceededException When the registry is at capacity.
     */
    public function register(
        string $correlationId,
        Duration $timeout,
        ActorPath $target,
        NodeAddress $targetNode,
    ): Future {
        if (count($this->pending) >= $this->maxPending) {
            throw new AskCapacityExceededException($this->maxPending, count($this->pending));
        }

        $slot = $this->runtime->createFutureSlot();
        $this->pending[$correlationId] = $slot;
        $this->startTimes[$correlationId] = hrtime(true);
        $this->targets[$correlationId] = $targetNode->toPathPrefix();

        $this->safely(fn(): mixed => $this->pendingGauge ??= $this->meter->observableGauge(
            'nexus.cluster.asks.pending',
            fn(): int => $this->count(),
            '{message}',
            'Number of in-flight remote cluster asks',
        ));

        $this->runtime->scheduleOnce($timeout, function () use ($correlationId, $target, $timeout): void {
            $startTime = $this->startTimes[$correlationId] ?? null;
            $slot = $this->remove($correlationId);

            if ($slot !== null) {
                $slot->fail(new AskTimeoutException($target, $timeout));
                $this->safely(fn(): mixed => $this->asksTimedOutCounter()->add(1));

                if ($startTime !== null) {
                    $durationMs = (hrtime(true) - $startTime) / 1_000_000;
                    $this->safely(fn(): mixed => $this->askDurationHistogram()->record($durationMs));
                }
            }
        });

        return new Future($slot);
    }

    /**
     * Resolve the pending ask for `$correlationId` with `$reply`. First reply wins; unknown,
     * late, or duplicate correlation IDs return false so the caller can count the drop.
     */
    public function resolve(string $correlationId, object $reply): bool
    {
        if (!isset($this->pending[$correlationId])) {
            return false;
        }

        $startTime = $this->startTimes[$correlationId] ?? null;
        $slot = $this->pending[$correlationId];
        unset($this->pending[$correlationId]);
        unset($this->startTimes[$correlationId]);
        unset($this->targets[$correlationId]);
        $slot->resolve($reply);

        $this->safely(fn(): mixed => $this->asksResolvedCounter()->add(1));

        if ($startTime !== null) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;
            $this->safely(fn(): mixed => $this->askDurationHistogram()->record($durationMs));
        }

        return true;
    }

    public function has(string $correlationId): bool
    {
        return isset($this->pending[$correlationId]);
    }

    public function count(): int
    {
        return count($this->pending);
    }

    /**
     * Fail every in-flight ask targeting `$node` with {@see PeerUnreachableException}. Called when a
     * peer's link closes: the reply can never arrive over the dead connection, so awaiting callers
     * fail fast instead of parking until their per-ask timeout, and a single dead peer can no longer
     * hold the registry toward its capacity limit and starve asks to healthy peers.
     *
     * @return int the number of asks failed
     */
    public function failAllForNode(NodeAddress $node): int
    {
        $prefix = $node->toPathPrefix();
        $failed = 0;

        foreach ($this->targets as $correlationId => $targetPrefix) {
            if ($targetPrefix !== $prefix) {
                continue;
            }

            $slot = $this->remove($correlationId);

            if ($slot !== null) {
                $slot->fail(new PeerUnreachableException($prefix));
                ++$failed;
            }
        }

        return $failed;
    }

    /**
     * @return FutureSlot<object>|null
     */
    private function remove(string $correlationId): ?FutureSlot
    {
        $slot = $this->pending[$correlationId] ?? null;
        unset($this->pending[$correlationId]);
        unset($this->startTimes[$correlationId]);
        unset($this->targets[$correlationId]);

        return $slot;
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break ask operations.
        }
    }

    private function asksResolvedCounter(): Counter
    {
        return $this->asksResolved ??= $this->meter->counter(
            'nexus.cluster.asks.resolved',
            '{message}',
            'Remote cluster asks resolved with a reply',
        );
    }

    private function asksTimedOutCounter(): Counter
    {
        return $this->asksTimedOut ??= $this->meter->counter(
            'nexus.cluster.asks.timed_out',
            '{message}',
            'Remote cluster asks that timed out without a reply',
        );
    }

    private function askDurationHistogram(): Histogram
    {
        return $this->askDuration ??= $this->meter->histogram(
            'nexus.cluster.ask.duration',
            'ms',
            'Round-trip duration of remote cluster asks',
        );
    }
}
