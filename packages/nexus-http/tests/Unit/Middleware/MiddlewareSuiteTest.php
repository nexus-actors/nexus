<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Middleware\BearerTokenMiddleware;
use Monadial\Nexus\Http\Middleware\LoggingMiddleware;
use Monadial\Nexus\Http\Middleware\RequestIdMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $seen = null;

    public function __construct(private readonly int $status = 200) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = $request;

        return new Response($this->status);
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{context: array<array-key, mixed>, level: mixed, message: string}> */
    public array $records = [];

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'context' => $context,
            'level' => $level,
            'message' => (string) $message,
        ];
    }
}

#[CoversClass(BearerTokenMiddleware::class)]
#[CoversClass(LoggingMiddleware::class)]
#[CoversClass(RequestIdMiddleware::class)]
final class MiddlewareSuiteTest extends TestCase
{
    #[Test]
    public function request_id_uses_existing_header_when_present(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')->withHeader('X-Request-Id', 'abc');
        $handler = new CapturingHandler();
        $response = (new RequestIdMiddleware())->process($request, $handler);
        self::assertSame('abc', $response->getHeaderLine('X-Request-Id'));
        self::assertNotNull($handler->seen);
        self::assertSame('abc', $handler->seen->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function request_id_generates_when_missing(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $handler = new CapturingHandler();
        $response = (new RequestIdMiddleware())->process($request, $handler);
        self::assertNotEmpty($response->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function bearer_token_rejects_when_header_missing(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $response = (new BearerTokenMiddleware(['secret']))->process($request, new CapturingHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function bearer_token_passes_when_token_valid(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer secret');
        $response = (new BearerTokenMiddleware(['secret']))->process($request, new CapturingHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function logging_writes_access_record(): void
    {
        $logger = new CapturingLogger();
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/orders/42');
        (new LoggingMiddleware($logger))->process($request, new CapturingHandler(200));
        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
    }
}
