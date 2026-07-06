<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\Tcp\Exception\AskCapacityExceededException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;

use function count;

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

    public function __construct(private readonly Runtime $runtime, private readonly int $maxPending = 10_000) {}

    /**
     * Register a pending ask and schedule its timeout. The returned future resolves when a
     * matching reply arrives or fails with {@see AskTimeoutException} once `$timeout` elapses.
     *
     * @return Future<object>
     *
     * @throws AskCapacityExceededException When the registry is at capacity.
     */
    public function register(string $correlationId, Duration $timeout, ActorPath $target): Future
    {
        if (count($this->pending) >= $this->maxPending) {
            throw new AskCapacityExceededException($this->maxPending, count($this->pending));
        }

        $slot = $this->runtime->createFutureSlot();
        $this->pending[$correlationId] = $slot;

        $this->runtime->scheduleOnce($timeout, function () use ($correlationId, $target, $timeout): void {
            $this->remove($correlationId)?->fail(new AskTimeoutException($target, $timeout));
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

        $slot = $this->pending[$correlationId];
        unset($this->pending[$correlationId]);
        $slot->resolve($reply);

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
     * @return FutureSlot<object>|null
     */
    private function remove(string $correlationId): ?FutureSlot
    {
        $slot = $this->pending[$correlationId] ?? null;
        unset($this->pending[$correlationId]);

        return $slot;
    }
}
