<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Middleware\ExceptionHandlerMiddleware;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class _ThrowingHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Throwable $error) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->error;
    }
}

#[CoversClass(ExceptionHandlerMiddleware::class)]
final class ExceptionHandlerMiddlewareTest extends TestCase
{
    #[Test]
    public function falls_back_to_throwable_mapper_for_unmapped(): void
    {
        $mappers = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($mappers, ErrorMode::Production);
        $mw = new ExceptionHandlerMiddleware($mappers);

        $response = $mw->process(
            new ServerRequest('GET', '/u'),
            new _ThrowingHandler(new RuntimeException('boom')),
        );

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function maps_http_exception_to_response(): void
    {
        $mappers = new ExceptionMapperRegistry();
        DefaultMappers::registerInto($mappers, ErrorMode::Production);
        $mw = new ExceptionHandlerMiddleware($mappers);

        $response = $mw->process(
            new ServerRequest('GET', '/u'),
            new _ThrowingHandler(HttpException::notFound('nope')),
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
