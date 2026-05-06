<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Internal;

use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use ReflectionClass;
use ReflectionMethod;

/**
 * @psalm-internal Monadial\Nexus\Ddd\Core
 *
 * Resolves and invokes the `applyXxx` method on an entity for a given event.
 * Convention: method name = `apply` + event class short name (case-sensitive).
 * Per-class resolution is cached (one ReflectionMethod per (entityClass, eventClass)).
 *
 * Cross-namespace short-name collisions throw ApplyMethodAmbiguousException at
 * resolution time.
 */
final class ApplyDispatcher
{
    /** @var array<class-string, array<class-string, ReflectionMethod>> */
    private array $cache = [];

    /** @var array<class-string, array<string, list<class-string>>> */
    private array $shortNameIndex = [];

    public function dispatch(object $entity, object $event): void
    {
        $method = $this->resolve($entity::class, $event::class);
        $method->invoke($entity, $event);
    }

    /**
     * @param class-string $entityClass
     * @param class-string $eventClass
     */
    public function resolve(string $entityClass, string $eventClass): ReflectionMethod
    {
        if (isset($this->cache[$entityClass][$eventClass])) {
            return $this->cache[$entityClass][$eventClass];
        }

        $shortName = $this->shortName($eventClass);
        $methodName = 'apply' . $shortName;

        $reflection = new ReflectionClass($entityClass);

        if (! $reflection->hasMethod($methodName)) {
            throw ApplyMethodNotFoundException::for($entityClass, $eventClass);
        }

        $method = $reflection->getMethod($methodName);
        $this->cache[$entityClass][$eventClass] = $method;
        $this->shortNameIndex[$entityClass][$shortName][] = $eventClass;

        if (count($this->shortNameIndex[$entityClass][$shortName]) > 1) {
            throw ApplyMethodAmbiguousException::for(
                $entityClass,
                $shortName,
                $this->shortNameIndex[$entityClass][$shortName],
            );
        }

        return $method;
    }

    /** @param class-string $fqn */
    private function shortName(string $fqn): string
    {
        $parts = explode('\\', $fqn);

        return end($parts);
    }
}
