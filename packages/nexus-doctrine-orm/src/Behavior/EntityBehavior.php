<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;

/** @psalm-api */
final class EntityBehavior
{
    /**
     * @template T of object
     * @template C of object
     *
     * @param class-string<T> $entityClass
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T> $commandHandler
     * @return EntityBehaviorBuilder<T, C>
     */
    public static function create(string $entityClass, mixed $id, Closure $commandHandler): EntityBehaviorBuilder
    {
        return new EntityBehaviorBuilder($entityClass, $id, $commandHandler);
    }
}
