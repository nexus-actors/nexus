<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Exception;

use Doctrine\ORM\OptimisticLockException;
use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class EntityConflictException extends NexusException
{
    public function __construct(
        public readonly string $entityClass,
        public readonly mixed $id,
        OptimisticLockException $previous,
    ) {
        parent::__construct(
            sprintf('Optimistic lock conflict for %s::%s', $entityClass, (string) $id),
            0,
            $previous,
        );
    }
}
