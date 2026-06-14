<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Toolkit\Middleware\AccessLogMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

#[CoversClass(AccessLogMiddleware::class)]
final class AccessLogMiddlewareTest extends TestCase
{
    #[Test]
    public function logs_one_line_per_successful_request(): void
    {
        $log = new CapturingLogger();
        $mw = new AccessLogMiddleware($log);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost/orders/42');
        $handler = new StubHandler($factory->createResponse(200));

        $mw->process($request, $handler);

        self::assertCount(1, $log->records);
        self::assertSame('GET', $log->records[0]['context']['method']);
        self::assertSame('/orders/42', $log->records[0]['context']['path']);
        self::assertSame(200, $log->records[0]['context']['status']);
    }

    #[Test]
    public function logs_status_500_when_handler_throws(): void
    {
        $log = new CapturingLogger();
        $mw = new AccessLogMiddleware($log);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/boom');
        $handler = new ThrowingHandler(new RuntimeException('kaboom'));

        try {
            $mw->process($request, $handler);
            self::fail('expected exception to bubble');
        } catch (RuntimeException) {
            // expected
        }

        self::assertCount(1, $log->records);
        self::assertSame(500, $log->records[0]['context']['status']);
        self::assertSame(RuntimeException::class, $log->records[0]['context']['error']);
        self::assertSame('kaboom', $log->records[0]['context']['errorMessage']);
    }

    #[Test]
    public function latency_is_recorded_in_milliseconds(): void
    {
        $log = new CapturingLogger();
        $mw = new AccessLogMiddleware($log);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost/');
        $handler = new StubHandler($factory->createResponse(200));

        $mw->process($request, $handler);

        self::assertArrayHasKey('latencyMs', $log->records[0]['context']);
        /** @var int|float $latency */
        $latency = $log->records[0]['context']['latencyMs'];
        self::assertGreaterThanOrEqual(0, $latency);
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<array-key, mixed>}> */
    public array $records = [];

    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'context' => $context,
            'level'   => $level,
            'message' => $message,
        ];
    }
}

final readonly class StubHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseInterface $response) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

final readonly class ThrowingHandler implements RequestHandlerInterface
{
    public function __construct(private Throwable $throwable) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->throwable;
    }
}
