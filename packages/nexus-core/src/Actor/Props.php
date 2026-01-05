<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Functional\Option\Option;
use InvalidArgumentException;
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
    private function __construct(public Behavior $behavior, public MailboxConfig $mailbox, public Option $supervision,) {}

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

            /**
             * @psalm-suppress DocblockTypeContradiction runtime guard for untyped callables
             * @psalm-suppress MixedOperand $actor::class is safe for string concatenation
             */
            if (!$actor instanceof ActorHandler) {
                throw new InvalidArgumentException(
                    'Factory must return an instance of ' . ActorHandler::class . ', got ' . $actor::class,
                );
            }

            /** @psalm-suppress InvalidArgument template U is erased at runtime */
            $receive = Behavior::receive(
                static fn (ActorContext $c, object $msg): Behavior => $actor->handle($c, $msg),
            );

            if ($actor instanceof AbstractActor) {
                $actor->onPreStart($ctx);

                /** @psalm-suppress InvalidArgument template variance on signal closure */
                return $receive->onSignal(static function (ActorContext $c, Signal $signal) use ($actor): Behavior {
                    if ($signal instanceof PostStop) {
                        $actor->onPostStop($c);
                    }

                    return Behavior::same();
                });
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
     *
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement template U erased through closure into fromFactory
     */
    public static function fromContainer(ContainerInterface $container, string $actorClass): self
    {
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
        /** @psalm-suppress UnusedClosureParam $ctx required by setup() signature */
        $behavior = Behavior::setup(static function (ActorContext $ctx) use ($factory): Behavior {
            $actor = $factory();

            /**
             * @psalm-suppress DocblockTypeContradiction runtime guard for untyped callables
             * @psalm-suppress MixedOperand $actor::class is safe for string concatenation
             */
            if (!$actor instanceof StatefulActorHandler) {
                throw new InvalidArgumentException(
                    'Factory must return an instance of ' . StatefulActorHandler::class . ', got ' . $actor::class,
                );
            }

            /**
             * @psalm-suppress InvalidArgument template U is erased at runtime
             * @psalm-suppress MixedArgument $state is mixed at runtime; template S erased
             */
            return Behavior::withState(
                $actor->initialState(),
                static fn (ActorContext $c, object $msg, mixed $state): BehaviorWithState => $actor->handle(
                    $c,
                    $msg,
                    $state,
                ),
            );
        });

        return self::fromBehavior($behavior);
    }

    /**
     * @return Props<T>
     */
    public function withMailbox(MailboxConfig $config): self
    {
        return new self($this->behavior, $config, $this->supervision);
    }

    /**
     * @return Props<T>
     */
    public function withSupervision(object $strategy): self
    {
        return new self($this->behavior, $this->mailbox, Option::some($strategy));
    }
}
