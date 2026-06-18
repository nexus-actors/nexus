<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DriverException;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
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

#[CoversClass(ConnectionScopeMiddleware::class)]
final class ConnectionScopeMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesLeaseAndReleasesAfterHandle(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $middleware = new ConnectionScopeMiddleware($pool);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $lease = $request->getAttribute(ConnectionLease::class);
                TestCase::assertInstanceOf(ConnectionLease::class, $lease);
                $lease->get();

                return new Response(200);
            }
        };

        $factory17 = new Psr17Factory();
        $request = $factory17->createServerRequest('GET', '/');
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(1, $pool->stats()->idle);
    }

    #[Test]
    public function poisonsLeaseOnDbalException(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $middleware = new ConnectionScopeMiddleware($pool);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $lease = $request->getAttribute(ConnectionLease::class);
                TestCase::assertInstanceOf(ConnectionLease::class, $lease);
                $lease->get();

                /** @psalm-suppress InternalMethod */
                throw new DriverException(new PdoDriverException('connection lost'), null);
            }
        };

        $factory17 = new Psr17Factory();

        try {
            $middleware->process($factory17->createServerRequest('GET', '/'), $handler);
            self::fail('expected throw');
        } catch (DriverException) {
            // expected
        }

        // Poisoned: connection evicted, pool total drops back to 0.
        self::assertSame(0, $pool->stats()->total);
    }

    #[Test]
    public function nonDbalExceptionReleasesWithoutPoisoning(): void
    {
        $factory = new StubConnectionFactory();
        $pool = $this->pool($factory);
        $middleware = new ConnectionScopeMiddleware($pool);

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $lease = $request->getAttribute(ConnectionLease::class);
                TestCase::assertInstanceOf(ConnectionLease::class, $lease);
                $lease->get();

                // Domain / validation / 404 — does NOT corrupt the connection.
                throw new RuntimeException('validation failure');
            }
        };

        $factory17 = new Psr17Factory();

        try {
            $middleware->process($factory17->createServerRequest('GET', '/'), $handler);
            self::fail('expected throw');
        } catch (RuntimeException) {
            // expected
        }

        // Released, not poisoned: connection is still in the pool ready for reuse.
        self::assertSame(1, $pool->stats()->total);
        self::assertSame(1, $pool->stats()->idle);
        self::assertSame(0, $pool->stats()->inUse);
    }

    private function pool(StubConnectionFactory $factory): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: $factory,
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );
    }
}
