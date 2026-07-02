<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Support;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionFactory;
use Override;
use PHPUnit\Framework\MockObject\Generator\Generator;
use RuntimeException;

/**
 * Returns mock Connection instances. Tracks creations and allows tests
 * to inject pre-prepared mocks. Used by ConnectionPool unit tests (T9+).
 *
 * @psalm-api
 */
final class StubConnectionFactory implements ConnectionFactory
{
    public int $creations = 0;

    /** @var list<Connection> */
    private array $prepared = [];

    public function prepend(Connection $conn): void
    {
        $this->prepared[] = $conn;
    }

    #[Override]
    public function create(): Connection
    {
        $this->creations++;

        if ($this->prepared !== []) {
            return array_shift($this->prepared);
        }

        /**
         * @psalm-suppress InternalClass
         * @psalm-suppress InternalMethod
         * @var Connection $mock
         */
        $mock = (new Generator())->testDouble(
            Connection::class,
            true,
            null,
            [],
            '',
            false,
        );

        return $mock;
    }

    public function exhaustOrFail(): void
    {
        if ($this->prepared !== []) {
            throw new RuntimeException('Prepared connections not all consumed');
        }
    }
}
