<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\LeakDetector;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[CoversClass(LeakDetector::class)]
final class LeakDetectorTest extends TestCase
{
    #[Test]
    public function warnsOnBorrowOlderThanAcquireTtl(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            /**
             * @param array<array-key, mixed> $context
             */
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $factory = new StubConnectionFactory();
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(acquireTtl: Duration::nanos(1), max: 2, minIdle: 0),
            channel: new FiberChannel(2),
            logger: $logger,
        );
        $held = $pool->take();

        (new LeakDetector())->tick($pool, now: hrtime(true) + 1_000_000_000);

        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('orders', $logger->warnings[0]);
        $pool->release($held);
    }
}
