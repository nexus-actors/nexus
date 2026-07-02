<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\TransactionalEmDecorator;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(TransactionalEmDecorator::class)]
final class TransactionalEmDecoratorTest extends TestCase
{
    #[Test]
    public function wrapsHandlerInTransaction(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn(callable $fn): mixed => $fn($em));

        $lease = new EntityManagerLease($this->poolReturning($em));

        $inner = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(EntityManagerLease::class, $lease);

        $response = (new TransactionalEmDecorator($inner))->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $lease->release();
    }

    #[Test]
    public function throwsWhenLeaseMissing(): void
    {
        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $this->expectException(MissingEntityManagerScopeException::class);
        (new TransactionalEmDecorator($handler))->handle((new Psr17Factory())->createServerRequest('GET', '/'));
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    private function poolReturning(EntityManagerInterface $em): EntityManagerPool
    {
        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);

        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: $emFactory,
            connPool: $connPool,
            config: new EmPoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
    }
}
