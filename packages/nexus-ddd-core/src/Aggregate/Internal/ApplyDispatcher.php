<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Internal;

use Closure;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use ReflectionClass;

/**
 * @internal Used by AggregateRoot/EventSourcedAggregateRoot in the parent namespace;
 *           framework-internal — apps should not instantiate or call directly.
 *
 * Resolves and invokes the `applyXxx` method on an entity for a given event.
 * Convention: method name = `apply` + event class short name (case-sensitive).
 *
 * Per (entityClass, eventClass) pair the dispatcher caches a class-scoped Closure
 * (bound via Closure::bind to the entity's class scope) so subsequent dispatches
 * skip ReflectionMethod::invoke and use direct dynamic dispatch — only the first
 * resolution touches reflection.
 *
 * Cross-namespace short-name collisions throw ApplyMethodAmbiguousException at
 * resolution time.
 */
final class ApplyDispatcher
{
    /** @var array<class-string, array<class-string, Closure(object, DomainEvent): void>> */
    private array $cache = [];

    /** @var array<class-string, array<string, list<class-string>>> */
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
        $shortName = $this->shortName($eventClass);
        $methodName = 'apply' . $shortName;

        $reflection = new ReflectionClass($entityClass);

        if (! $reflection->hasMethod($methodName)) {
            throw ApplyMethodNotFoundException::for($entityClass, $eventClass);
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
        $this->shortNameIndex[$entityClass][$shortName][] = $eventClass;

        if (count($this->shortNameIndex[$entityClass][$shortName]) > 1) {
            throw ApplyMethodAmbiguousException::for(
                $entityClass,
                $shortName,
                $this->shortNameIndex[$entityClass][$shortName],
            );
        }

        return $invoker;
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
