<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Actor;

use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorPoolBinding::class)]
final class ActorPoolBindingTest extends TestCase
{
    #[Test]
    public function exposesConnPool(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $binding = new ActorPoolBinding($pool);

        self::assertSame($pool, $binding->connPool);
    }
}
