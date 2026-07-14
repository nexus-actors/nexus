<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\UpDownCounter;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Override;

/** @psalm-api */
final readonly class OtelUpDownCounter implements UpDownCounter
{
    public function __construct(private UpDownCounterInterface $upDownCounter) {}

    /**
     * @param array<string, scalar> $attributes
     *
     * @psalm-suppress InvalidArgument the OTel SDK requires non-empty-string keys; the framework
     *                 UpDownCounter contract accepts any string key, so keys are forwarded as-is.
     */
    #[Override]
    public function add(int|float $value, array $attributes = []): void
    {
        $this->upDownCounter->add($value, $attributes);
    }
}
