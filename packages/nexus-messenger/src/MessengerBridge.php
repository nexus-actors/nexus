<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger;

use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleWatchdog;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Producer\MessengerGateway;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Bootstrap conveniences for wiring the Messenger bridge in a few lines —
 * plain factories, no container magic.
 *
 * Example (Nexus-owned queue worker):
 * ```php
 * $system->spawn(MessengerBridge::receiverProps($transport, $router), 'in');
 * $system->spawn(MessengerBridge::watchdogProps(
 *     $system,
 *     LifecycleThresholds::none()->withMessageLimit(10_000),
 * ), 'watchdog');
 * $orders = MessengerBridge::producer($transport, 'orders-out');
 * ```
 *
 * @psalm-api
 */
final readonly class MessengerBridge
{
    private function __construct()
    {
    }

    public static function gateway(
        SenderInterface $sender,
        Observability $observability = new NoopObservability(),
        ?EventDispatcherInterface $events = null,
    ): MessengerGateway {
        return new MessengerGateway($sender, $observability, $events);
    }

    /**
     * @template T of object
     * @return MessengerActorRef<T>
     * @psalm-suppress InvalidReturnType,InvalidReturnStatement Psalm cannot infer T through MessengerActorRef<object> constructor
     */
    public static function producer(
        SenderInterface $sender,
        string $name,
        ?ActorPath $sourcePath = null,
        Observability $observability = new NoopObservability(),
        ?EventDispatcherInterface $events = null,
    ): MessengerActorRef {
        return new MessengerActorRef($sender, $name, $sourcePath, $observability, $events);
    }

    /**
     * @param ActorRef<object>|null $deadLetters
     * @param ActorRef<object>|null $processedListener
     * @return Props<object>
     */
    public static function receiverProps(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
        ?EventDispatcherInterface $events = null,
        ?Observability $observability = null,
    ): Props {
        return Props::fromBehavior(
            ReceiverActor::create(
                $receiver,
                $router,
                $config,
                $deadLetters,
                $processedListener,
                $events,
                $observability,
            ),
        );
    }

    /**
     * Spawn N competing ReceiverActors over the same receiver — in-process
     * horizontal scaling. Actors are named "<namePrefix>-0" … "<namePrefix>-{N-1}"
     * and each polls, routes, and acks independently; because acks happen only
     * on accepted enqueue, competing consumers preserve at-least-once
     * semantics. For scaling across processes or machines, run multiple
     * worker processes instead — the broker load-balances between them.
     *
     * @param ActorRef<object>|null $deadLetters
     * @param ActorRef<object>|null $processedListener
     * @return list<ActorRef<object>>
     */
    public static function spawnReceivers(
        ActorSystem $system,
        int $count,
        string $namePrefix,
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
        ?EventDispatcherInterface $events = null,
        ?Observability $observability = null,
    ): array {
        if ($count < 1) {
            throw new InvalidArgumentException('Receiver count must be at least 1.');
        }

        $events ??= $system->eventDispatcher();
        $refs = [];

        for ($i = 0; $i < $count; $i++) {
            $refs[] = $system->spawn(
                self::receiverProps(
                    $receiver,
                    $router,
                    $config,
                    $deadLetters,
                    $processedListener,
                    $events,
                    $observability,
                ),
                $namePrefix . '-' . $i,
            );
        }

        return $refs;
    }

    /**
     * @param Closure(): int|null $memoryProbe
     * @return Props<object>
     */
    public static function watchdogProps(
        ActorSystem $system,
        LifecycleThresholds $thresholds,
        ?Duration $checkInterval = null,
        ?Duration $shutdownTimeout = null,
        ?Closure $memoryProbe = null,
    ): Props {
        return Props::fromBehavior(
            LifecycleWatchdog::create($system, $thresholds, $checkInterval, $shutdownTimeout, $memoryProbe),
        );
    }
}
