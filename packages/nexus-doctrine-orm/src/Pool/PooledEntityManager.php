<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-api */
final class PooledEntityManager extends EntityManagerDecorator
{
    private int $borrows = 0;

    public function __construct(EntityManagerInterface $wrapped)
    {
        parent::__construct($wrapped);
    }

    public function markBorrowed(): void
    {
        $this->borrows++;
    }

    public function borrowCount(): int
    {
        return $this->borrows;
    }
}
