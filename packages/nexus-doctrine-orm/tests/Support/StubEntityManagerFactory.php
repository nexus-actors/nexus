<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Override;
use PHPUnit\Framework\MockObject\Generator\Generator;
use RuntimeException;

/**
 * Returns mock EntityManager instances. Tracks creations and allows
 * tests to inject pre-prepared mocks. Mirrors the StubConnectionFactory
 * pattern from nexus-doctrine-dbal.
 *
 * @psalm-api
 */
final class StubEntityManagerFactory implements EntityManagerFactory
{
    public int $creations = 0;

    /** @var list<EntityManagerInterface> */
    private array $prepared = [];

    public function prepend(EntityManagerInterface $em): void
    {
        $this->prepared[] = $em;
    }

    #[Override]
    public function create(Connection $connection): EntityManagerInterface
    {
        $this->creations++;

        if ($this->prepared !== []) {
            return array_shift($this->prepared);
        }

        /**
         * @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $mock
         */
        $mock = (new Generator())->testDouble(
            EntityManagerInterface::class,
            true,
            null,
            [],
            '',
            false,
        );

        // release() sanitizes the EM's underlying connection, so the double
        // must expose a clean connection with no active transaction.
        /**
         * @var Connection&\PHPUnit\Framework\MockObject\MockObject $conn
         */
        $conn = (new Generator())->testDouble(Connection::class, true, null, [], '', false);
        $conn->method('isTransactionActive')->willReturn(false);
        $mock->method('getConnection')->willReturn($conn);

        return $mock;
    }

    public function exhaustOrFail(): void
    {
        if ($this->prepared !== []) {
            throw new RuntimeException('Prepared EMs not all consumed');
        }
    }
}
