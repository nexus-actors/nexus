<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;

/** @psalm-api */
final class EntityManagerLease
{
    private ?PooledEntityManager $em = null;

    public function __construct(private readonly EntityManagerPool $pool) {}

    public function get(): EntityManagerInterface
    {
        return $this->em ??= $this->pool->take();
    }

    public function release(): void
    {
        if ($this->em === null) {
            return;
        }

        $this->pool->release($this->em);
        $this->em = null;
    }
}
