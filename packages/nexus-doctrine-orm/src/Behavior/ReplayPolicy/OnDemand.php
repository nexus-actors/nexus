<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @psalm-api
 */
final readonly class OnDemand implements EntityReplayPolicy
{
    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T|null
     */
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): ?object
    {
        return null;
    }
}
