<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/** @psalm-api */
final readonly class DefaultEntityManagerFactory implements EntityManagerFactory
{
    public function __construct(private Configuration $configuration) {}

    #[Override]
    public function create(Connection $connection): EntityManagerInterface
    {
        return new EntityManager($connection, $this->configuration);
    }
}
