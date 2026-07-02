<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;

/**
 * @template T of object
 * @psalm-api
 */
interface EntityReplayPolicy
{
    /**
     * @param class-string<T> $entityClass
     * @return T|null
     */
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): ?object;
}
