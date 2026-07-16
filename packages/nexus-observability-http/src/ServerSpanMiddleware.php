<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function implode;
use function strtolower;

/**
 * @psalm-api
 *
 * PSR-15 middleware that opens an OpenTelemetry Server span for each request.
 * It is the span source of truth: it extracts the inbound trace context, starts
 * and activates the span (so any actor `ask`/`tell` inside the handler links as
 * a child), records `http.*` attributes and the response status, records handler
 * exceptions, and always ends the span. All telemetry is fail-isolated — a
 * telemetry error never breaks the request; the handler's own exception still
 * propagates to the exception-handling middleware.
 */
final readonly class ServerSpanMiddleware implements MiddlewareInterface
{
    public function __construct(private Observability $observability) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->observability->isEnabled()) {
            return $handler->handle($request);
        }

        $span = $this->startSpan($request);

        try {
            $response = $handler->handle($request);
            $this->safely(static function () use ($span, $response): void {
                $statusCode = $response->getStatusCode();
                $span?->setAttribute('http.response.status_code', $statusCode);
                $span?->setStatus(
                    $statusCode >= 500
                        ? StatusCode::Error
                        : StatusCode::Unset,
                );
            });

            return $response;
        } catch (Throwable $e) {
            $this->safely(static function () use ($span, $e): void {
                $span?->recordException($e);
                $span?->setStatus(StatusCode::Error, $e->getMessage());
            });

            throw $e;
        } finally {
            $this->safely(static fn(): mixed => $span?->end());
        }
    }

    private function startSpan(ServerRequestInterface $request): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                'HTTP ' . $request->getMethod(),
                SpanKind::Server,
                [
                    'http.request.method' => $request->getMethod(),
                    'url.path' => $request->getUri()->getPath(),
                ],
                $this->extractParent($request),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function extractParent(ServerRequestInterface $request): Context
    {
        $carrier = [];

        foreach ($request->getHeaders() as $name => $values) {
            // PSR-7 types header names as array-key: PHP re-keys numeric header
            // names (e.g. "123") to int, so cast back to string.
            $carrier[strtolower((string) $name)] = implode(',', $values);
        }

        return $this->observability->propagator()->extract($carrier);
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break the request.
        }
    }
}
