<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Persistence\Dbal\DbalPessimisticLockProvider;
use Monadial\Nexus\Persistence\Locking\PessimisticLockProvider;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Doctrine ORM convenience wrapper for pessimistic locking.
 *
 * Delegates to DbalPessimisticLockProvider via the EntityManager's connection.
 */
final class DoctrinePessimisticLockProvider implements PessimisticLockProvider
{
    private readonly DbalPessimisticLockProvider $inner;

    public function __construct(EntityManagerInterface $em)
    {
        $this->inner = new DbalPessimisticLockProvider($em->getConnection());
    }

    public function withLock(PersistenceId $id, Closure $callback): mixed
    {
        return $this->inner->withLock($id, $callback);
    }
}
