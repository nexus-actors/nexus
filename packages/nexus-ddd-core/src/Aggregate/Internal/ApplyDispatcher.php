<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Internal;

use Closure;
use Monadial\Nexus\Ddd\Core\Aggregate\Attribute\AppliesTo;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use ReflectionClass;

/**
 * @internal Used by AggregateRoot/EventSourcedAggregateRoot in the parent namespace;
 *           framework-internal — apps should not instantiate or call directly.
 *
 * Resolves and invokes the `applyXxx` method on an entity for a given event.
 *
 * **Resolution order** (first match wins):
 *   1. `#[AppliesTo('explicitMethodName')]` attribute on the event class.
 *      Use this for versioned events (V1 / V2 in different namespaces that
 *      would otherwise short-name-collide).
 *   2. Convention: method name = `apply` + event class short name
 *      (case-sensitive).
 *
 * Per (entityClass, eventClass) pair the dispatcher caches a class-scoped Closure
 * (bound via Closure::bind to the entity's class scope) so subsequent dispatches
 * skip ReflectionMethod::invoke and use direct dynamic dispatch — only the first
 * resolution touches reflection.
 *
 * Cross-namespace short-name collisions throw ApplyMethodAmbiguousException at
 * resolution time. Resolve them by adding `#[AppliesTo(...)]` to one or both
 * events.
 */
final class ApplyDispatcher
{
    /** @var array<class-string, array<class-string, Closure(object, DomainEvent): void>> */
    private array $cache = [];

    /**
     * @var array<class-string, array<string, array<class-string, true>>>
     *
     * Indexed-by-eventClass-as-key so concurrent first-dispatches from
     * cooperating coroutines that hit the same (entity, event) pair don't
     * spuriously inflate the count and produce a false collision.
     */
    private array $shortNameIndex = [];

    public function dispatch(object $entity, DomainEvent $event): void
    {
        $entityClass = $entity::class;
        $eventClass = $event::class;

        $invoker = $this->cache[$entityClass][$eventClass]
            ?? $this->resolve($entityClass, $eventClass);

        $invoker($entity, $event);
    }

    /**
     * @param class-string $entityClass
     * @param class-string<DomainEvent> $eventClass
     * @return Closure(object, DomainEvent): void
     */
    private function resolve(string $entityClass, string $eventClass): Closure
    {
        $eventReflection = new ReflectionClass($eventClass);
        $explicit = $this->explicitMethodName($eventReflection);
        $methodName = $explicit ?? 'apply' . $this->shortName($eventClass);

        $entityReflection = new ReflectionClass($entityClass);

        if (! $entityReflection->hasMethod($methodName)) {
            throw ApplyMethodNotFoundException::for($entityClass, $eventClass);
        }

        // Short-name collision detection ONLY applies to convention-based
        // resolution. Events with explicit `#[AppliesTo(...)]` opt out of
        // the index — that is precisely the escape hatch's purpose.
        if ($explicit === null) {
            $shortName = $this->shortName($eventClass);
            $this->shortNameIndex[$entityClass][$shortName][$eventClass] = true;

            if (count($this->shortNameIndex[$entityClass][$shortName]) > 1) {
                foreach (array_keys($this->shortNameIndex[$entityClass][$shortName]) as $colliding) {
                    unset($this->cache[$entityClass][$colliding]);
                }

                throw ApplyMethodAmbiguousException::for(
                    $entityClass,
                    $shortName,
                    array_keys($this->shortNameIndex[$entityClass][$shortName]),
                );
            }
        }

        /** @var Closure(object, DomainEvent): void $invoker */
        $invoker = Closure::bind(
            static function (object $entity, DomainEvent $event) use ($methodName): void {
                /** @psalm-suppress MixedMethodCall */
                $entity->$methodName($event);
            },
            null,
            $entityClass,
        );

        $this->cache[$entityClass][$eventClass] = $invoker;

        return $invoker;
    }

    /**
     * @param ReflectionClass<DomainEvent> $eventReflection
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    private function explicitMethodName(ReflectionClass $eventReflection): ?string
    {
        $attributes = $eventReflection->getAttributes(AppliesTo::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->methodName;
    }

    /** @param class-string $fqn */
    private function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        /** @var string|null $last */
        $last = array_last($parts);

        return $last ?? $fqn;
    }
}
