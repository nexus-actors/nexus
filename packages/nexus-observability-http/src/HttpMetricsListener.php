<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener that records RED metrics from the HTTP request lifecycle
 * events. Register `onRequestStarted` for {@see RequestStarted} and
 * `onRequestCompleted` for {@see RequestCompleted}. No-op when observability is
 * disabled. Metric dimensions are deliberately low-cardinality (method + status
 * code) to keep the metrics backend healthy.
 */
final class HttpMetricsListener
{
    private ?UpDownCounter $activeRequests = null;

    private ?Histogram $duration = null;

    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function onRequestStarted(RequestStarted $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $this->activeRequests()->add(1, ['http.request.method' => $event->request->getMethod()]);
    }

    public function onRequestCompleted(RequestCompleted $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $method = $event->request->getMethod();

        $this->activeRequests()->add(-1, ['http.request.method' => $method]);
        $this->duration()->record($event->durationNanos / 1_000_000_000, [
            'http.request.method' => $method,
            'http.response.status_code' => $event->response->getStatusCode(),
        ]);
    }

    private function activeRequests(): UpDownCounter
    {
        return $this->activeRequests ??= $this->observability->meter()->upDownCounter(
            'http.server.active_requests',
            '{request}',
            'Number of in-flight HTTP server requests',
        );
    }

    private function duration(): Histogram
    {
        return $this->duration ??= $this->observability->meter()->histogram(
            'http.server.request.duration',
            's',
            'Duration of HTTP server requests',
        );
    }
}
