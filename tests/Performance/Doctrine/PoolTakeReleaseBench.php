<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\Doctrine;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

#[CoversNothing]
#[RequiresPhpExtension('swoole')]
final class PoolTakeReleaseBench extends TestCase
{
    /**
     * @psalm-suppress UnusedFunctionCall
     */
    #[Test]
    public function takeReleaseAvgUnder200us(): void
    {
        DoctrineBootstrap::enable();
        $avgNs = 0;

        run(static function () use (&$avgNs): void {
            $pool = DoctrinePool::fromParams(
                name: 'bench',
                connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
                config: new PoolConfig(max: 1, minIdle: 0),
            );
            $iterations = 10_000;

            // Warm up: ensure the connection is in the idle channel before timing.
            $warmup = $pool->take(Duration::seconds(1));
            $pool->release($warmup);

            $start = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $conn = $pool->take(Duration::seconds(1));
                $pool->release($conn);
            }

            $avgNs = intdiv(hrtime(true) - $start, $iterations);
        });

        // Conservative ceiling — typical hardware sees <50µs but CI/Docker overhead
        // varies widely. This threshold is a regression guard, not an absolute target.
        self::assertLessThan(
            200_000,
            $avgNs,
            sprintf('avg per take+release: %d ns (%.2f µs)', $avgNs, $avgNs / 1000),
        );
    }
}
