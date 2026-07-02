<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class OnDemand implements EntityReplayPolicy
{
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): ?object
    {
        return null;
    }
}
