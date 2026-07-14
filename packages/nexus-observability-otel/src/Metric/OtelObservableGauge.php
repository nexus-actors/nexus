<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\ObservableGauge;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;

/**
 * @psalm-api
 *
 * Holds the OTEL observable gauge handle so the registered callback stays alive
 * for the lifetime of this wrapper.
 *
 * @psalm-suppress UnusedProperty the handle is held only to keep the registered gauge callback
 *                 alive for the lifetime of this wrapper; it is never read directly.
 */
final readonly class OtelObservableGauge implements ObservableGauge
{
    public function __construct(private ObservableGaugeInterface $gauge) {}
}
