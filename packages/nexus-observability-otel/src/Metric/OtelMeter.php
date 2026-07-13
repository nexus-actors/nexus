<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Override;

/**
 * @psalm-api
 *
 * Adapts an OpenTelemetry {@see MeterInterface} to the Nexus {@see Meter}
 * contract.
 */
final readonly class OtelMeter implements Meter
{
    public function __construct(private MeterInterface $meter) {}

    #[Override]
    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new OtelCounter($this->meter->createCounter($name, $unit, $description));
    }

    #[Override]
    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new OtelUpDownCounter($this->meter->createUpDownCounter($name, $unit, $description));
    }

    #[Override]
    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new OtelHistogram($this->meter->createHistogram($name, $unit, $description));
    }

    /**
     * @param callable(): (int|float) $callback
     */
    #[Override]
    public function observableGauge(
        string $name,
        callable $callback,
        string $unit = '',
        string $description = '',
    ): ObservableGauge {
        $gauge = $this->meter->createObservableGauge(
            $name,
            $unit,
            $description,
            [],
            static function (ObserverInterface $observer) use ($callback): void {
                $observer->observe($callback());
            },
        );

        return new OtelObservableGauge($gauge);
    }
}
