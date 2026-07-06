<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Messenger\Event\AskTimedOut;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Orchestrates broker ask/reply mechanics on the asker side.
 *
 * Owns the {@see PendingAskRegistry}, the reply channel lifecycle, and timeout
 * scheduling. The first call to {@see replyChannelName()} lazily creates the
 * reply channel via the factory, spawns a {@see ReplyConsumer} actor named
 * {@code nexus-ask-replies} under the ActorSystem root, and is idempotent on
 * subsequent calls.
 *
 * {@see ask()} registers a {@see \Monadial\Nexus\Runtime\Async\FutureSlot} in
 * the registry and schedules a timeout that fails the slot and emits
 * telemetry when the deadline is missed. Callers are responsible for sending
 * the stamped request envelope to the request transport and returning the
 * resulting Future to their own callers.
 *
 * Example (wired via MessengerBridge):
 * ```php
 * $askSupport = MessengerBridge::askSupport($system, $channelFactory);
 * $ref = MessengerBridge::producer($requestSender, 'orders-out', askSupport: $askSupport);
 * // Inside an actor fiber:
 * $reply = $ref->ask(new Ping('hello'), Duration::seconds(5))->await();
 * ```
 *
 * @psalm-api
 */
final class AskSupport
{
    private ?ReplyChannel $channel = null;

    public function __construct(
        private readonly ActorSystem $system,
        private readonly ReplyChannelFactory $channelFactory,
        private readonly PendingAskRegistry $registry,
        private readonly Duration $pollInterval,
        private readonly Observability $observability = new NoopObservability(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    public function registry(): PendingAskRegistry
    {
        return $this->registry;
    }

    /**
     * Return the logical reply-channel name, lazily creating the channel and
     * spawning the nexus-ask-replies consumer on the first call. Idempotent.
     */
    public function replyChannelName(): string
    {
        if ($this->channel !== null) {
            return $this->channel->name();
        }

        $this->channel = $this->channelFactory->create();

        $this->system->spawn(
            Props::fromBehavior(ReplyConsumer::create(
                $this->channel,
                $this->registry,
                $this->pollInterval,
                $this->events,
            )),
            'nexus-ask-replies',
        );

        return $this->channel->name();
    }

    /**
     * Register a pending ask and schedule its timeout.
     *
     * Creates a FutureSlot via the system runtime, registers it in the
     * registry under `$correlationId`, and schedules a one-shot timer that
     * fails the slot with {@see AskTimeoutException} if no reply arrives before
     * `$timeout` elapses.
     *
     * @throws \Monadial\Nexus\Messenger\Exception\AskCapacityExceededException when the registry is at capacity
     */
    public function ask(object $message, Duration $timeout, string $correlationId): Future
    {
        $slot = $this->system->runtime()->createFutureSlot();
        $this->registry->register($correlationId, $slot);

        $registry = $this->registry;
        $observability = $this->observability;
        $events = $this->events;
        $path = ActorPath::fromString('/messenger/ask');

        $this->system->runtime()->scheduleOnce(
            $timeout,
            static function () use ($correlationId, $registry, $slot, $timeout, $path, $observability, $events): void {
                if ($registry->remove($correlationId) !== null) {
                    $slot->fail(new AskTimeoutException($path, $timeout));
                    self::swallow(static fn(): mixed => $observability->meter()->counter(
                        'nexus.messenger.asks.timed_out',
                        '{ask}',
                        'Ask requests that expired without receiving a reply',
                    )->add(1));
                    self::swallow(static fn(): mixed => $events?->dispatch(new AskTimedOut($correlationId)));
                }
            },
        );

        return new Future($slot);
    }

    /**
     * Close the reply channel. Call during ActorSystem shutdown to release
     * transport resources.
     */
    public function close(): void
    {
        $this->channel?->close();
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
