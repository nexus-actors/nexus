<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class OptimisticLockException extends NexusDddException
{
    public function __construct(
        public readonly string $entityClass,
        public readonly string $entityId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(
            sprintf(
                'Optimistic lock conflict on %s(%s): expected version %d, found %d.',
                $entityClass,
                $entityId,
                $expectedVersion,
                $actualVersion,
            ),
        );
    }
}
