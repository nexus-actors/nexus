<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Override;
use RuntimeException;

/**
 * `EntityManagerFactory` seam that hands back a real EntityManager for
 * find/persist (so replay works), but whose `flush()` always throws — a
 * stand-in for an infra failure (dead connection, driver error) that the
 * real store would surface only at write time.
 */
final readonly class FlushFailingEntityManagerFactory implements EntityManagerFactory
{
    public function __construct(
        private EntityManagerFactory $inner,
        private string $failureMessage = 'flush failed: connection lost',
    ) {}

    #[Override]
    public function create(Connection $connection): EntityManagerInterface
    {
        $delegate = $this->inner->create($connection);

        return new class ($delegate, $this->failureMessage) extends EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly string $message)
            {
                parent::__construct($wrapped);
            }

            #[Override]
            public function flush(): void
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
