<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Immutable W3C Baggage: a set of cross-cutting string key/value pairs that
 * propagate alongside trace context, independent of sampling.
 */
final readonly class Baggage
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public array $values,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function with(string $key, string $value): self
    {
        $values = $this->values;
        $values[$key] = $value;

        return new self($values);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
