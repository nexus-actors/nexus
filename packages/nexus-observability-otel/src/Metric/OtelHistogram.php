<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Histogram;
use OpenTelemetry\API\Metrics\HistogramInterface;
use Override;

/** @psalm-api */
final readonly class OtelHistogram implements Histogram
{
    public function __construct(private HistogramInterface $histogram,) {}

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function record(int|float $value, array $attributes = []): void
    {
        $this->histogram->record($value, $attributes);
    }
}
