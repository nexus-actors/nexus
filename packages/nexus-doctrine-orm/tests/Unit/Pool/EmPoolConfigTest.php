<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmPoolConfig::class)]
final class EmPoolConfigTest extends TestCase
{
    #[Test]
    public function defaultsMatchSpec(): void
    {
        $config = new EmPoolConfig();

        self::assertSame(16, $config->max);
        self::assertSame(2, $config->minIdle);
        self::assertTrue($config->borrowTimeout->equals(Duration::seconds(5)));
        self::assertTrue($config->clearOnReturn);
        self::assertSame(1000, $config->recreateAfter);
    }

    #[Test]
    public function rejectsInvalidMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmPoolConfig(max: 0);
    }

    #[Test]
    public function rejectsMinIdleAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmPoolConfig(max: 2, minIdle: 5);
    }

    #[Test]
    public function rejectsNegativeRecreateAfter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EmPoolConfig(recreateAfter: -1);
    }

    #[Test]
    public function recreateAfterZeroIsAllowed(): void
    {
        $config = new EmPoolConfig(recreateAfter: 0);
        self::assertSame(0, $config->recreateAfter);
    }
}
