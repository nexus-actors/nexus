<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core;

use Brick\Math\BigInteger;
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
 */
final readonly class Duration implements Stringable
{
    private const int NANOS_PER_MICRO  = 1_000;
    private const int NANOS_PER_MILLI  = 1_000_000;
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private function __construct(private BigInteger $nanos,) {}

    // -- Factory methods ------------------------------------------------------

    public static function seconds(int $seconds): self
    {
        return new self(BigInteger::of($seconds)->multipliedBy(self::NANOS_PER_SECOND));
    }

    public static function millis(int $millis): self
    {
        return new self(BigInteger::of($millis)->multipliedBy(self::NANOS_PER_MILLI));
    }

    public static function micros(int $micros): self
    {
        return new self(BigInteger::of($micros)->multipliedBy(self::NANOS_PER_MICRO));
    }

    public static function nanos(int $nanos): self
    {
        return new self(BigInteger::of($nanos));
    }

    public static function zero(): self
    {
        return new self(BigInteger::zero());
    }

    // -- Arithmetic -----------------------------------------------------------

    #[NoDiscard]
    public function plus(self $other): self
    {
        return new self($this->nanos->plus($other->nanos));
    }

    #[NoDiscard]
    public function minus(self $other): self
    {
        return new self($this->nanos->minus($other->nanos));
    }

    #[NoDiscard]
    public function multipliedBy(int $factor): self
    {
        return new self($this->nanos->multipliedBy($factor));
    }

    #[NoDiscard]
    public function dividedBy(int $divisor): self
    {
        return new self($this->nanos->quotient($divisor));
    }

    // -- Conversions ----------------------------------------------------------

    public function toNanos(): int
    {
        return $this->nanos->toInt();
    }

    public function toMicros(): int
    {
        return $this->nanos->quotient(self::NANOS_PER_MICRO)->toInt();
    }

    public function toMillis(): int
    {
        return $this->nanos->quotient(self::NANOS_PER_MILLI)->toInt();
    }

    public function toSeconds(): int
    {
        return $this->nanos->quotient(self::NANOS_PER_SECOND)->toInt();
    }

    public function toSecondsFloat(): float
    {
        return $this->nanos->toInt() / self::NANOS_PER_SECOND;
    }

    // -- Comparisons ----------------------------------------------------------

    public function equals(self $other): bool
    {
        return $this->nanos->isEqualTo($other->nanos);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos->isGreaterThan($other->nanos);
    }

    public function isLessThan(self $other): bool
    {
        return $this->nanos->isLessThan($other->nanos);
    }

    public function isZero(): bool
    {
        return $this->nanos->isZero();
    }

    public function compareTo(self $other): int
    {
        return $this->nanos->compareTo($other->nanos);
    }

    // -- Stringable -----------------------------------------------------------

    #[Override]
    public function __toString(): string
    {
        $totalNanos = $this->nanos->abs();
        $parts = [];

        $seconds = $totalNanos->quotient(self::NANOS_PER_SECOND);
        $remainder = $totalNanos->mod(self::NANOS_PER_SECOND);

        $millis = $remainder->quotient(self::NANOS_PER_MILLI);
        $remainder = $remainder->mod(self::NANOS_PER_MILLI);

        $micros = $remainder->quotient(self::NANOS_PER_MICRO);
        $nanos = $remainder->mod(self::NANOS_PER_MICRO);

        if (!$seconds->isZero()) {
            $parts[] = $seconds->toInt() . 's';
        }

        if (!$millis->isZero()) {
            $parts[] = $millis->toInt() . 'ms';
        }

        if (!$micros->isZero()) {
            $parts[] = $micros->toInt() . "\u{03BC}s";
        }

        if (!$nanos->isZero()) {
            $parts[] = $nanos->toInt() . 'ns';
        }

        if ($parts === []) {
            return '0ns';
        }

        $result = implode(' ', $parts);

        return $this->nanos->isNegative()
            ? '-' . $result
            : $result;
    }
}
