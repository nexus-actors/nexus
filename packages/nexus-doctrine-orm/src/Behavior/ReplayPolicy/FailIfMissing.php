<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class FailIfMissing implements EntityReplayPolicy
{
    /**
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
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
