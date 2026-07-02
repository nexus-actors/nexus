<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\Swoole;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Dbal\Bootstrap\DoctrineBootstrap;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\WaitGroup;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

#[RequiresPhpExtension('swoole')]
final class ConcurrentEmBorrowTest extends TestCase
{
    #[Test]
    public function eightCoroutinesShareTwoEms(): void
    {
        DoctrineBootstrap::enable();
        /** @var array<int, int> $results */
        $results = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$results): void {
            $ormConfig = ORMSetup::createAttributeMetadataConfig(paths: []);
            $ormConfig->enableNativeLazyObjects(true);

            $pool = DoctrineEmPool::forConfig(
                name: 'concurrent-em',
                connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
                ormSetup: $ormConfig,
                config: new EmPoolConfig(max: 2, minIdle: 0),
            );
            /** @psalm-suppress UndefinedClass */
            $wg = new WaitGroup();

            for ($i = 0; $i < 8; $i++) {
                /** @psalm-suppress UndefinedClass */
                $wg->add();
                /** @psalm-suppress UnusedFunctionCall */
                go(static function () use ($pool, $wg, $i, &$results): void {
                    $results[$i] = $pool->withEntityManager(
                        static fn(EntityManagerInterface $em): int => (int) $em->getConnection()->fetchOne('SELECT 42'),
                    );
                    /** @psalm-suppress UndefinedClass */
                    $wg->done();
                });
            }

            /** @psalm-suppress UndefinedClass */
            $wg->wait();
        });

        self::assertCount(8, $results);

        foreach ($results as $r) {
            self::assertSame(42, $r);
        }
    }
}
