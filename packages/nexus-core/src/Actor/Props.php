<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Psr\Container\ContainerInterface;

/**
 * @psalm-api
 *
 * @template T of object
 */
final readonly class Props
{
    /**
     * @param Behavior<T> $behavior
     * @param Option<object> $supervision  Will be typed as SupervisionStrategy in Task 5b
     */
    private function __construct(public Behavior $behavior, public MailboxConfig $mailbox, public Option $supervision) {}

    /**
     * @template U of object
     * @param Behavior<U> $behavior
     * @return Props<U>
     */
    public static function fromBehavior(Behavior $behavior): self
    {
        /** @var Option<object> $none */
        $none = Option::none();

        return new self($behavior, MailboxConfig::unbounded(), $none);
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
        $behavior = Behavior::setup(static function (ActorContext $ctx) use ($factory): Behavior {
            $actor = $factory();
            assert($actor instanceof ActorHandler, 'Factory must return an ActorHandler');

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

                return $receive->onSignal($signalHandler);
            }

            return $receive;
        });

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
            assert($handler instanceof ActorHandler);

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
            $actor = $factory();
            assert($actor instanceof StatefulActorHandler, 'Factory must return a StatefulActorHandler');

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
     * @return Props<T>
     */
    public function withMailbox(MailboxConfig $config): self
    {
        return clone($this, ['mailbox' => $config]);
    }

    /**
     * @return Props<T>
     */
    public function withSupervision(object $strategy): self
    {
        return clone($this, ['supervision' => Option::some($strategy)]);
    }
}
