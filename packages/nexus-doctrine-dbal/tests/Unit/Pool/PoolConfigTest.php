<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolConfig::class)]
final class PoolConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesMatchSpec(): void
    {
        $config = new PoolConfig();

        self::assertSame(16, $config->max);
        self::assertSame(2, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(5)));
        self::assertTrue($config->idleTtl->equals(Duration::seconds(300)));
        self::assertTrue($config->acquireTtl->equals(Duration::seconds(30)));
        self::assertFalse($config->healthCheckOnBorrow);
        self::assertSame('SELECT 1', $config->validationQuery);
    }

    #[Test]
    public function customValuesOverrideDefaults(): void
    {
        $config = new PoolConfig(
            acquireTtl: Duration::seconds(60),
            borrowTimeout: Duration::seconds(1),
            healthCheckOnBorrow: true,
            idleTtl: Duration::seconds(120),
            max: 32,
            minIdle: 4,
            validationQuery: 'SELECT 42',
        );

        self::assertSame(32, $config->max);
        self::assertSame(4, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(1)));
        self::assertTrue($config->idleTtl->equals(Duration::seconds(120)));
        self::assertTrue($config->acquireTtl->equals(Duration::seconds(60)));
        self::assertTrue($config->healthCheckOnBorrow);
        self::assertSame('SELECT 42', $config->validationQuery);
    }

    #[Test]
    public function minIdleCannotExceedMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('minIdle (10) must not exceed max (5)');

        new PoolConfig(max: 5, minIdle: 10);
    }

    #[Test]
    public function maxMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PoolConfig(max: 0);
    }
}
