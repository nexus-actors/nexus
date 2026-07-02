<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Closure;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pool-exhaustion behaviour end-to-end through the middleware stack.
 *
 * The composition under test matches the documented production wiring:
 *
 *     500 Mapper (outermost — catches PoolExhaustedException from inner layers)
 *       └── ConnectionScopeMiddleware (acquires lazily on $lease->get())
 *             └── Handler that pulls the connection
 *
 * If the handler asks for a connection while the pool is exhausted, the
 * scope middleware's `$lease->get()` throws `PoolExhaustedException`, which
 * the outer `PoolExhaustedToServiceUnavailable` catches and turns into a
 * 503 with `Retry-After: 1`. Anything else bubbles.
 */
#[CoversNothing]
final class PoolExhaustionIntegrationTest extends TestCase
{
    #[Test]
    public function exhaustedPoolYields503WithRetryAfter(): void
    {
        $pool = $this->saturatedPool();

        // The pool's single slot is already taken; a fresh request will exhaust.
        $stack = $this->stack($pool);
        $response = $stack(
            (new Psr17Factory())->createServerRequest('GET', '/orders'),
            $this->handlerThatNeedsConnection(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function nonExhaustionErrorsBypassThe503Mapper(): void
    {
        $pool = $this->freshPool();
        $stack = $this->stack($pool);

        $response = $stack(
            (new Psr17Factory())->createServerRequest('GET', '/orders'),
            new class implements RequestHandlerInterface {
                #[Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(404, [], 'not found');
                }
            },
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function handlerThatNeedsConnection(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $lease = $request->getAttribute(ConnectionLease::class);
                TestCase::assertInstanceOf(ConnectionLease::class, $lease);
                // The exhaustion exception is thrown here; PoolExhaustedToServiceUnavailable catches it.
                $lease->get();

                return new Response(200);
            }
        };
    }

    /**
     * Pool with max=1, all slots in use. The held connection is leaked
     * intentionally so the next take() exhausts the pool within the
     * borrow timeout.
     */
    private function saturatedPool(): ConnectionPool
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(borrowTimeout: Duration::millis(10), max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        $pool->take();

        return $pool;
    }

    private function freshPool(): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 2, minIdle: 0),
            channel: new FiberChannel(2),
        );
    }

    /**
     * Returns a closure that wires the middleware order: 503 mapper outermost,
     * then ConnectionScopeMiddleware, then the handler.
     */
    private function stack(ConnectionPool $pool): Closure
    {
        $mapper = new PoolExhaustedToServiceUnavailable(new Psr17Factory());
        $scope = new ConnectionScopeMiddleware($pool);

        return static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($mapper, $scope): ResponseInterface {
            $inner = self::wrap($scope, $handler);

            return $mapper->process($request, $inner);
        };
    }

    private static function wrap(MiddlewareInterface $mw, RequestHandlerInterface $inner): RequestHandlerInterface
    {
        return new class ($mw, $inner) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $mw,
                private readonly RequestHandlerInterface $inner,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->mw->process($request, $this->inner);
            }
        };
    }
}
