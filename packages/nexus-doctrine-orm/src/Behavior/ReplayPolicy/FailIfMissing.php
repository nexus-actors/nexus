<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Override;

/**
 * @psalm-api
 */
final readonly class FailIfMissing implements EntityReplayPolicy
{
    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T
     */
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): object
    {
        $entity = $em->find($entityClass, $id);

        if ($entity === null) {
            throw EntityNotFoundException::fromClassNameAndIdentifier($entityClass, ['id' => (string) $id]);
        }

        return $entity;
    }
}
