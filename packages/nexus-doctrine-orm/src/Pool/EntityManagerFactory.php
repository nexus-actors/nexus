<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** @psalm-api */
interface EntityManagerFactory
{
    public function create(Connection $connection): EntityManagerInterface;
}
