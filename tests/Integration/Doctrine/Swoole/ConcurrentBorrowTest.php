<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Swoole;

use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\WaitGroup;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

#[RequiresPhpExtension('swoole')]
final class ConcurrentBorrowTest extends TestCase
{
    #[Test]
    public function tenCoroutinesShareTwoConnections(): void
    {
        DoctrineBootstrap::enable();
        $results = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$results): void {
            $pool = DoctrinePool::fromParams(
                name: 'concurrent',
                connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
                config: new PoolConfig(borrowTimeout: Duration::seconds(5), max: 2, minIdle: 0),
            );
            /** @psalm-suppress UndefinedClass */
            $wg = new WaitGroup();

            for ($i = 0; $i < 10; $i++) {
                /** @psalm-suppress UndefinedClass */
                $wg->add();
                /** @psalm-suppress UnusedFunctionCall */
                go(static function () use ($pool, $wg, $i, &$results): void {
                    $results[$i] = $pool->withConnection(
                        static fn($c): int => (int) $c->fetchOne('SELECT 42'),
                    );
                    /** @psalm-suppress UndefinedClass */
                    $wg->done();
                });
            }

            /** @psalm-suppress UndefinedClass */
            $wg->wait();
        });

        self::assertCount(10, $results);

        foreach ($results as $r) {
            self::assertSame(42, $r);
        }
    }
}
