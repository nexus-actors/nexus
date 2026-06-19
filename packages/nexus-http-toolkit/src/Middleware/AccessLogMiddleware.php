<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function hrtime;
use function round;

/**
 * @psalm-api
 *
 * Structured access log middleware. Emits one PSR-3 log line per request
 * with method, path, status, response size, and latency in ms. Designed
 * to be the outermost middleware after ExceptionHandlerMiddleware so it
 * sees the final status code regardless of where the response was
 * produced.
 *
 * Pair with MDC populated upstream (requestId, principal, traceId) to
 * get full correlation in your log aggregator.
 *
 * Example output via nexus-logger's LineFormatter:
 *
 *   [2026-06-14T13:50:01.234Z] http.INFO: GET /orders/42 200 (12.41ms)
 *     {"method":"GET","path":"/orders/42","status":200,"sizeBytes":312,
 *      "latencyMs":12.41,"requestId":"a1b2c3d4"}
 */
final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $messageTemplate = '{method} {path} {status} ({latencyMs}ms)',
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startNs = hrtime(true);
        $thrown = null;
        $response = null;

        try {
            // phpcs:ignore SlevomatCodingStandard.Variables.UselessVariable.UselessVariable
            $response = $handler->handle($request);

            return $response;
        } catch (Throwable $e) {
            $thrown = $e;

            throw $e;
        } finally {
            $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;
            $this->emit($request, $response, $thrown, $elapsedMs);
        }
    }

    private function emit(
        ServerRequestInterface $request,
        ?ResponseInterface $response,
        ?Throwable $thrown,
        float $elapsedMs,
    ): void {
        if ($response !== null) {
            $status = $response->getStatusCode();
            $sizeBytes = $response->getBody()->getSize() ?? 0;
        } else {
            $status = $thrown !== null
                ? 500
                : 0;
            $sizeBytes = 0;
        }

        $context = [
            'latencyMs' => round($elapsedMs, 2),
            'method'    => $request->getMethod(),
            'path'      => $request->getUri()->getPath(),
            'sizeBytes' => $sizeBytes,
            'status'    => $status,
        ];

        if ($thrown !== null) {
            $context['error'] = $thrown::class;
            $context['errorMessage'] = $thrown->getMessage();
        }

        $this->logger->info($this->messageTemplate, $context);
    }
}
