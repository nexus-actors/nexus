<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DriverManagerConnectionFactory::class)]
final class DriverManagerConnectionFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsConnection(): void
    {
        $factory = new DriverManagerConnectionFactory(['driver' => 'pdo_sqlite', 'memory' => true]);

        $conn = $factory->create();

        self::assertInstanceOf(Connection::class, $conn);
        $conn->close();
    }

    #[Test]
    public function eachCallReturnsFreshInstance(): void
    {
        $factory = new DriverManagerConnectionFactory(['driver' => 'pdo_sqlite', 'memory' => true]);

        $a = $factory->create();
        $b = $factory->create();

        self::assertNotSame($a, $b);
        $a->close();
        $b->close();
    }
}
