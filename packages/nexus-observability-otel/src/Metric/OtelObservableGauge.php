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
 */
final readonly class OtelObservableGauge implements ObservableGauge
{
    public function __construct(private ObservableGaugeInterface $gauge) {}
}
