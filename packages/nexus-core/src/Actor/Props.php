<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Monadial\Nexus\Core\Exception\InvalidPropsFactoryException;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Psr\Container\ContainerInterface;

/**
 * Immutable spawn configuration for an actor.
 *
 * Props bundles the behavior factory with optional mailbox capacity and
 * supervision strategy overrides. You pass a Props value to ActorSystem::spawn()
 * or ActorContext::spawn() to create a new actor. The static factory methods
 * (fromBehavior, fromFactory, fromStatefulFactory, fromContainer) cover the
 * four actor definition styles supported by Nexus.
 *
 * Example:
 * ```php
 * // Closure-based
 * $props = Props::fromBehavior(Behavior::receive(fn($ctx, $msg) => Behavior::same()));
 *
 * // Class-based (fresh instance per spawn)
 * $props = Props::fromFactory(fn() => new OrderActor($repository));
 *
 * // Class-based from PSR-11 container
 * $props = Props::fromContainer($container, PaymentActor::class);
 *
 * // Override mailbox capacity and supervision strategy
 * $props = Props::fromBehavior($behavior)
 *     ->withMailbox(MailboxConfig::bounded(100, OverflowStrategy::DropNewest))
 *     ->withSupervision(SupervisionStrategy::oneForOne(maxRetries: 3));
 * ```
 *
 * @see Behavior for the behavior factory methods
 * @see ActorSystem::spawn() for spawning top-level actors
 * @see ActorContext::spawn() for spawning child actors
 * @see MailboxConfig for mailbox capacity options
 *
 * @psalm-api
 *
 * @template T of object
 */
final readonly class Props
{
    /**
     * @param Behavior<T> $behavior
     */
    private function __construct(
        public Behavior $behavior,
        public MailboxConfig $mailbox,
        public ?SupervisionStrategy $supervision,
    ) {}

    /**
     * Create Props from a pre-built Behavior instance.
     *
     * This is the simplest factory — use it when you already have a Behavior
     * value (e.g. from Behavior::receive() or Behavior::setup()) and do not
     * need to change mailbox or supervision defaults.
     *
     * @template U of object
     * @param Behavior<U> $behavior
     * @return Props<U>
     */
    public static function fromBehavior(Behavior $behavior): self
    {
        return new self($behavior, MailboxConfig::unbounded(), null);
    }

    /**
     * Create Props from a callable factory that produces an ActorHandler.
     *
     * A fresh instance is created per spawn inside Behavior::setup.
     * If the instance extends AbstractActor, lifecycle hooks (onPreStart, onPostStop)
     * are wired automatically.
     *
     * @template U of object
     * @param callable(): ActorHandler<U> $factory
     * @return Props<U>
     */
    public static function fromFactory(callable $factory): self
    {
        $behavior = Behavior::setup(/** @return Behavior<U> */
            static function (ActorContext $ctx) use ($factory): Behavior {
                /** @var object $actor Runtime contract check: callable return types are not enforced by PHP */
                $actor = $factory();

                if (!$actor instanceof ActorHandler) {
                    throw new InvalidPropsFactoryException(
                        'Props::fromFactory() factory',
                        ActorHandler::class,
                        $actor,
                    );
                }

                /** @var ActorHandler<U> $actor Restore the template param verified by the guard */

                $receive = Behavior::receive(
                    /** @param ActorContext<U> $c @param U $msg @return Behavior<U> */
                    static function (ActorContext $c, object $msg) use ($actor): Behavior {
                        /** @var ActorContext<U> $c */
                        /** @var U $msg */

                        return $actor->handle($c, $msg);
                    },
                );

                if ($actor instanceof AbstractActor) {
                    $actor->onPreStart($ctx);

                    /** @var Closure(ActorContext<U>, Signal): Behavior<U> $signalHandler */
                    $signalHandler = static function (ActorContext $c, Signal $signal) use ($actor): Behavior {
                        if ($signal instanceof PostStop) {
                            $actor->onPostStop($c);
                        }

                        return Behavior::same();
                    };

                    /** @var Behavior<U> $result */
                    $result = $receive->onSignal($signalHandler);

                    return $result;
                }

                return $receive;
            },
        );

        return self::fromBehavior($behavior);
    }

    /**
     * Create Props from a PSR-11 container and actor class name.
     *
     * Resolves a fresh instance per spawn via $container->get($actorClass).
     *
     * @template U of object
     * @param class-string<ActorHandler<U>> $actorClass
     * @return Props<U>
     */
    public static function fromContainer(ContainerInterface $container, string $actorClass): self
    {
        /** @var Props<U> */
        return self::fromFactory(static function () use ($container, $actorClass): ActorHandler {
            $handler = $container->get($actorClass);

            if (!$handler instanceof ActorHandler) {
                throw new InvalidPropsFactoryException(
                    "Props::fromContainer() container entry '{$actorClass}'",
                    ActorHandler::class,
                    $handler,
                );
            }

            return $handler;
        });
    }

    /**
     * Create Props from a callable factory that produces a StatefulActorHandler.
     *
     * A fresh instance is created per spawn. Uses Behavior::withState internally.
     *
     * @template U of object
     * @template S
     * @param callable(): StatefulActorHandler<U, S> $factory
     * @return Props<U>
     */
    public static function fromStatefulFactory(callable $factory): self
    {
        $behavior = Behavior::setup(static function (ActorContext $_ctx) use ($factory): Behavior {
            /** @var object $actor Runtime contract check: callable return types are not enforced by PHP */
            $actor = $factory();

            if (!$actor instanceof StatefulActorHandler) {
                throw new InvalidPropsFactoryException(
                    'Props::fromStatefulFactory() factory',
                    StatefulActorHandler::class,
                    $actor,
                );
            }

            /** @var StatefulActorHandler<U, S> $actor Restore the template params verified by the guard */

            return Behavior::withState(
                $actor->initialState(),
                static function (ActorContext $c, object $msg, mixed $state) use ($actor): BehaviorWithState {
                    /** @var ActorContext<U> $typedCtx */
                    $typedCtx = $c;
                    /** @var U $typedMsg */
                    $typedMsg = $msg;
                    /** @var S $typedState */
                    $typedState = $state;

                    return $actor->handle($typedCtx, $typedMsg, $typedState);
                },
            );
        });

        return self::fromBehavior($behavior);
    }

    /**
     * Return a new Props with the given mailbox configuration.
     *
     * The default is an unbounded mailbox. Use MailboxConfig::bounded() when
     * you need back-pressure or message-drop semantics.
     *
     * @return Props<T>
     */
    public function withMailbox(MailboxConfig $config): self
    {
        return clone($this, ['mailbox' => $config]);
    }

    /**
     * Return a new Props with the given supervision strategy.
     *
     * Overrides the default one-for-one restart strategy. Use this to configure
     * exponential backoff or all-for-one restarts on a specific actor.
     *
     * @return Props<T>
     */
    public function withSupervision(SupervisionStrategy $strategy): self
    {
        return clone($this, ['supervision' => $strategy]);
    }
}
