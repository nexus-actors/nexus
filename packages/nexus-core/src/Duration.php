<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core;

use NoDiscard;
use Override;
use Stringable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Nanosecond-precision, immutable duration value object.
 *
 * All arithmetic methods return a new Duration instance; the original is never mutated.
 * Uses plain int64 internally for zero-overhead conversions in hot paths.
 */
final readonly class Duration implements Stringable
{
    private const int NANOS_PER_MICRO  = 1_000;
    private const int NANOS_PER_MILLI  = 1_000_000;
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private function __construct(private int $nanos)
    {
    }

    // -- Factory methods ------------------------------------------------------

    public static function seconds(int $seconds): self
    {
        return new self($seconds * self::NANOS_PER_SECOND);
    }

    public static function millis(int $millis): self
    {
        return new self($millis * self::NANOS_PER_MILLI);
    }

    public static function micros(int $micros): self
    {
        return new self($micros * self::NANOS_PER_MICRO);
    }

    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    // -- Arithmetic -----------------------------------------------------------

    #[NoDiscard]
    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    #[NoDiscard]
    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    #[NoDiscard]
    public function multipliedBy(int $factor): self
    {
        return new self($this->nanos * $factor);
    }

    #[NoDiscard]
    public function dividedBy(int $divisor): self
    {
        return new self(intdiv($this->nanos, $divisor));
    }

    // -- Conversions ----------------------------------------------------------

    public function toNanos(): int
    {
        return $this->nanos;
    }

    public function toMicros(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_MICRO);
    }

    public function toMillis(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_MILLI);
    }

    public function toSeconds(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_SECOND);
    }

    public function toSecondsFloat(): float
    {
        return $this->nanos / self::NANOS_PER_SECOND;
    }

    // -- Comparisons ----------------------------------------------------------

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function isLessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function compareTo(self $other): int
    {
        return $this->nanos <=> $other->nanos;
    }

    // -- Stringable -----------------------------------------------------------

    #[Override]
    public function __toString(): string
    {
        $totalNanos = abs($this->nanos);
        $parts = [];

        $seconds = intdiv($totalNanos, self::NANOS_PER_SECOND);
        $remainder = $totalNanos % self::NANOS_PER_SECOND;

        $millis = intdiv($remainder, self::NANOS_PER_MILLI);
        $remainder %= self::NANOS_PER_MILLI;

        $micros = intdiv($remainder, self::NANOS_PER_MICRO);
        $nanos = $remainder % self::NANOS_PER_MICRO;

        if ($seconds !== 0) {
            $parts[] = $seconds . 's';
        }

        if ($millis !== 0) {
            $parts[] = $millis . 'ms';
        }

        if ($micros !== 0) {
            $parts[] = $micros . "\u{03BC}s";
        }

        if ($nanos !== 0) {
            $parts[] = $nanos . 'ns';
        }

        if ($parts === []) {
            return '0ns';
        }

        $result = implode(' ', $parts);

        return $this->nanos < 0
            ? '-' . $result
            : $result;
    }
}
