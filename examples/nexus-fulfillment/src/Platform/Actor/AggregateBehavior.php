<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Actor;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\AggregateRoot;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function class_exists;
use function count;

/**
 * Builds the full Behavior for aggregate entity and saga process actors.
 *
 * Command handlers are discovered from the aggregate class by SIGNATURE CONVENTION:
 * every public, non-static method with exactly one parameter whose declared type
 * is a concrete class (not built-in, not abstract, not interface) is registered
 * as the handler for that message class. `apply()` is auto-excluded because its
 * parameter type is `object` (built-in). Ambiguity (two methods claiming the same
 * message class) throws LogicException at build time. Discovery runs once per
 * aggregate class (static cache keyed by class-string).
 *
 * @psalm-api
 */
final class AggregateBehavior
{
    /** @var array<class-string, array<class-string, string>> Discovery cache: aggregate class → (message class → method name) */
    private static array $handlerCache = [];

    /**
     * Build a Behavior for an aggregate entity actor (replies + engine-level event publication).
     *
     * Reply idiom:
     *   - Unknown command        → Effect::unhandled() (dead-lettered; no reply)
     *   - No events drained      → Effect::reply($sender, $accepted($agg)) with null-sender guard
     *   - RejectionEvent drained → Effect::persist(...)->thenRun(reply $rejected($next, $reason))
     *   - Success events drained → Effect::persist(...)->thenRun(reply $accepted($next))
     *
     * The engine invokes $publisher for each persisted event after fold, before thenRun.
     * thenRun closures therefore do NOT publish.
     *
     * @template TAgg of AggregateRoot
     *
     * @param TAgg $aggregate Empty aggregate (before any events)
     * @param Closure(TAgg): object $accepted Build the accepted reply from the post-fold aggregate
     * @param Closure(TAgg, string): object $rejected Build the rejected reply + reason
     * @param Closure(object): void $publisher Invoked by the engine for each persisted event
     */
    public static function for(
        object $aggregate,
        PersistenceId $persistenceId,
        Closure $accepted,
        Closure $rejected,
        EventStore $store,
        SnapshotStore $snapshots,
        Closure $publisher,
        Duration $passivateAfter,
    ): Behavior {
        $handlers = self::discoverHandlers($aggregate::class);

        $es = EventSourcedBehavior::create(
            $persistenceId,
            $aggregate,
            static function (object $agg, ActorContext $ctx, object $msg) use ($handlers, $accepted, $rejected): Effect {
                assert($agg instanceof AggregateRoot);

                $methodName = $handlers[$msg::class] ?? null;

                if ($methodName === null) {
                    return Effect::unhandled();
                }

                (new ReflectionMethod($agg, $methodName))->invoke($agg, $msg);

                $sender = $ctx->sender();
                $events = $agg->releaseEvents();

                if ($events === []) {
                    if ($sender === null) {
                        return Effect::none();
                    }

                    /** @psalm-suppress InvalidArgument — $agg is TAgg at runtime; $accepted declared Closure(TAgg): object */
                    return Effect::reply($sender, $accepted($agg));
                }

                $rejectionEvent = null;

                foreach ($events as $event) {
                    if ($event instanceof RejectionEvent) {
                        $rejectionEvent = $event;
                        break;
                    }
                }

                if ($rejectionEvent !== null) {
                    $reason = $rejectionEvent->reason();

                    return Effect::persist(...$events)->thenRun(
                        static function (object $next) use ($sender, $rejected, $reason): void {
                            /** @psalm-suppress ArgumentTypeCoercion — $next is TAgg at runtime; $rejected declared Closure(TAgg, string): object */
                            $sender?->tell($rejected($next, $reason));
                        },
                    );
                }

                return Effect::persist(...$events)->thenRun(
                    static function (object $next) use ($sender, $accepted): void {
                        /** @psalm-suppress ArgumentTypeCoercion — $next is TAgg at runtime; $accepted declared Closure(TAgg): object */
                        $sender?->tell($accepted($next));
                    },
                );
            },
            static function (object $agg, object $event): object {
                assert($agg instanceof AggregateRoot);
                $agg->apply($event);

                return $agg;
            },
        )
            ->withEventPublisher($publisher)
            ->withEventStore($store)
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false))
            ->withSignalHandler(static function (ActorContext $ctx, object $signal): Behavior {
                if ($signal instanceof ReceiveTimeout) {
                    return Behavior::stopped();
                }

                return Behavior::same();
            })
            ->withSnapshotStore($snapshots)
            ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
            ->toBehavior();

        /** @psalm-suppress InvalidArgument — $es is Behavior<TAgg> built by PersistenceEngine; generic T resolves at runtime */
        return Behavior::setup(static function (ActorContext $ctx) use ($es, $passivateAfter): Behavior {
            $ctx->setReceiveTimeout($passivateAfter);

            return $es;
        });
    }

    /**
     * Build a Behavior for a saga process actor (no replies, no event publication).
     *
     * Routing:
     *   - Unknown command      → Effect::unhandled()
     *   - No events drained    → Effect::none()
     *   - Events drained       → Effect::persist(...)->thenRun($sideEffects($next, $persisted))
     *
     * @template TAgg of AggregateRoot
     *
     * @param TAgg $aggregate Empty aggregate (before any events)
     * @param Closure(TAgg, list<object>): void $sideEffects Called in thenRun after persist with
     *                                                        the post-fold aggregate and persisted events
     */
    public static function forProcess(
        object $aggregate,
        PersistenceId $persistenceId,
        Closure $sideEffects,
        EventStore $store,
        SnapshotStore $snapshots,
        Duration $passivateAfter,
    ): Behavior {
        $handlers = self::discoverHandlers($aggregate::class);

        $es = EventSourcedBehavior::create(
            $persistenceId,
            $aggregate,
            static function (object $agg, ActorContext $ctx, object $msg) use ($handlers, $sideEffects): Effect {
                assert($agg instanceof AggregateRoot);

                $methodName = $handlers[$msg::class] ?? null;

                if ($methodName === null) {
                    return Effect::unhandled();
                }

                (new ReflectionMethod($agg, $methodName))->invoke($agg, $msg);

                $events = $agg->releaseEvents();

                if ($events === []) {
                    return Effect::none();
                }

                return Effect::persist(...$events)->thenRun(
                    static function (object $next) use ($events, $sideEffects): void {
                        /** @psalm-suppress ArgumentTypeCoercion — $next is TAgg at runtime; $sideEffects declared Closure(TAgg, list<object>): void */
                        $sideEffects($next, $events);
                    },
                );
            },
            static function (object $agg, object $event): object {
                assert($agg instanceof AggregateRoot);
                $agg->apply($event);

                return $agg;
            },
        )
            ->withEventStore($store)
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false))
            ->withSignalHandler(static function (ActorContext $ctx, object $signal): Behavior {
                if ($signal instanceof ReceiveTimeout) {
                    return Behavior::stopped();
                }

                return Behavior::same();
            })
            ->withSnapshotStore($snapshots)
            ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
            ->toBehavior();

        /** @psalm-suppress InvalidArgument — $es is Behavior<TAgg> built by PersistenceEngine; generic T resolves at runtime */
        return Behavior::setup(static function (ActorContext $ctx) use ($es, $passivateAfter): Behavior {
            $ctx->setReceiveTimeout($passivateAfter);

            return $es;
        });
    }

    /**
     * Discover command handlers for an aggregate class by signature convention.
     *
     * Rules: public, non-static, non-constructor method declaring `: void` with
     * exactly one parameter whose declared type is a concrete class (not built-in,
     * not abstract, not interface). `apply()` is auto-excluded (its parameter type
     * is `object`); value-returning query helpers are excluded by the void
     * requirement. CAVEAT: a public VOID method taking a non-message class WOULD
     * be claimed — keep aggregate public surfaces to intention methods only.
     * Discovery results are cached per aggregate class.
     *
     * @param class-string $class
     *
     * @return array<class-string, string> Map of message class → method name
     *
     * @throws LogicException if two methods claim the same message class
     */
    private static function discoverHandlers(string $class): array
    {
        if (isset(self::$handlerCache[$class])) {
            return self::$handlerCache[$class];
        }

        $handlers = [];
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            // Intention methods MUST declare `: void` — a value-returning
            // method taking a single class-typed param (e.g. a query helper
            // `totalFor(Sku $sku): Money`) is NOT a command handler and is
            // excluded here. Void mutators taking a non-message class would
            // still be claimed: keep aggregate public surfaces to intention
            // methods only, or make helpers private.
            $returnType = $method->getReturnType();

            if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== 'void') {
                continue;
            }

            $params = $method->getParameters();

            if (count($params) !== 1) {
                continue;
            }

            $type = $params[0]->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $paramClass = $type->getName();

            if (!class_exists($paramClass)) {
                continue;
            }

            $paramReflection = new ReflectionClass($paramClass);

            if ($paramReflection->isAbstract() || $paramReflection->isInterface()) {
                continue;
            }

            if (isset($handlers[$paramClass])) {
                throw new LogicException(
                    "Ambiguous handlers: {$class} has two public methods claiming message type {$paramClass}",
                );
            }

            $handlers[$paramClass] = $method->getName();
        }

        self::$handlerCache[$class] = $handlers;

        return $handlers;
    }
}
