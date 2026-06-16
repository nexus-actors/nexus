<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(PoolExhaustedToServiceUnavailable::class)]
final class PoolExhaustedToServiceUnavailableTest extends TestCase
{
    #[Test]
    public function maps503WithRetryAfter(): void
    {
        $middleware = new PoolExhaustedToServiceUnavailable(new Psr17Factory());

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw PoolExhaustedException::after('orders', PoolStats::empty());
            }
        };

        $response = $middleware->process(
            (new Psr17Factory())->createServerRequest('GET', '/'),
            $handler,
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function nonPoolExceptionsBubble(): void
    {
        $middleware = new PoolExhaustedToServiceUnavailable(new Psr17Factory());

        $handler = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('something else');
            }
        };

        $this->expectException(RuntimeException::class);
        $middleware->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
    }
}
