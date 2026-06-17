<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
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

#[CoversClass(EntityManagerScopeMiddleware::class)]
final class EntityManagerScopeMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesLeaseAndReleasesAfterHandle(): void
    {
        $pool = $this->pool();
        $middleware = new EntityManagerScopeMiddleware($pool);
        $capturedLease = null;

        $handler = new class ($capturedLease) implements RequestHandlerInterface {
            public function __construct(private mixed &$captured) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request->getAttribute(EntityManagerLease::class);
                /** @var EntityManagerLease $lease */
                $lease = $this->captured;
                $lease->get();

                return new Response(200);
            }
        };

        $response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(EntityManagerLease::class, $capturedLease);
        self::assertSame(0, $pool->stats()->inUse);
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    private function pool(): EntityManagerPool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

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
