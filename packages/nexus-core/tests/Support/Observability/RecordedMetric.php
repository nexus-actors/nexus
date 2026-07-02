<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Support\Observability;

final readonly class RecordedMetric
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public string $instrument,
        public string $name,
        public int|float $value,
        public array $attributes,
    ) {}
}
