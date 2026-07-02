<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Metric;

use Monadial\Nexus\Observability\Metric\Counter;
use OpenTelemetry\API\Metrics\CounterInterface;
use Override;

/** @psalm-api */
final readonly class OtelCounter implements Counter
{
    public function __construct(private CounterInterface $counter,) {}

    /**
     * @param array<string, scalar> $attributes
     */
    #[Override]
    public function add(int|float $value, array $attributes = []): void
    {
        $this->counter->add($value, $attributes);
    }
}
