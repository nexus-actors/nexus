<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\TransactionalDecorator;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(Transactional::class)]
#[CoversClass(TransactionalDecorator::class)]
final class TransactionalDecoratorTest extends TestCase
{
    #[Test]
    public function commitsOnSuccess(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->expects(self::once())->method('beginTransaction');
        $mock->expects(self::once())->method('commit');
        $mock->expects(self::never())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($mock);
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);

        $inner = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(ConnectionLease::class, $lease);
        $decorator = new TransactionalDecorator($inner);

        $decorator->handle($request);

        $lease->release();
    }

    #[Test]
    public function rollsBackOnThrow(): void
    {
        $mock = $this->createMock(Connection::class);
        $mock->expects(self::once())->method('beginTransaction');
        $mock->expects(self::never())->method('commit');
        $mock->expects(self::once())->method('rollBack');

        $factory = new StubConnectionFactory();
        $factory->prepend($mock);
        $pool = new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);

        $inner = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(ConnectionLease::class, $lease);

        try {
            (new TransactionalDecorator($inner))->handle($request);
            self::fail('expected throw');
        } catch (RuntimeException) {
            // expected
        }

        $lease->release();
    }
}
