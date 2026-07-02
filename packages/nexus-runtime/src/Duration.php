<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime;

use NoDiscard;
use Override;
use Stringable;

/**
 * Nanosecond-precision, immutable duration value object.
 *
 * Duration is the standard way to express time spans throughout Nexus — timeouts,
 * scheduled delays, backoff windows, and mailbox blocking calls all accept a Duration.
 * The value is stored as a single int64 nanosecond count, giving zero-overhead
 * conversions in hot paths without floating-point drift. All arithmetic methods
 * return a new instance; the original is never mutated.
 *
 * Example:
 * ```php
 * $timeout  = Duration::seconds(5);
 * $backoff  = Duration::millis(200)->multipliedBy(3);   // 600 ms
 * $total    = $timeout->plus($backoff);                  // 5s 600ms
 *
 * $ref->ask(new GetCount(), $timeout);
 * $ctx->scheduleOnce(Duration::millis(500), fn() => $system->shutdown($timeout));
 * ```
 *
 * @see Runtime::scheduleOnce() for timer-based usage
 * @see ActorRef::ask() for request-response timeouts
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class Duration implements Stringable
{
    private const int NANOS_PER_MICRO  = 1_000;
    private const int NANOS_PER_MILLI  = 1_000_000;
    private const int NANOS_PER_SECOND = 1_000_000_000;

    private function __construct(private int $nanos) {}

    // -- Factory methods ------------------------------------------------------

    /**
     * Create a Duration of the given number of whole seconds.
     */
    public static function seconds(int $seconds): self
    {
        return new self($seconds * self::NANOS_PER_SECOND);
    }

    /**
     * Create a Duration of the given number of milliseconds.
     */
    public static function millis(int $millis): self
    {
        return new self($millis * self::NANOS_PER_MILLI);
    }

    /**
     * Create a Duration of the given number of microseconds.
     */
    public static function micros(int $micros): self
    {
        return new self($micros * self::NANOS_PER_MICRO);
    }

    /**
     * Create a Duration of the given number of nanoseconds.
     */
    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    /**
     * Create a zero-length Duration.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    // -- Arithmetic -----------------------------------------------------------

    /**
     * Return a new Duration equal to this duration plus the given duration.
     */
    #[NoDiscard]
    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    /**
     * Return a new Duration equal to this duration minus the given duration.
     */
    #[NoDiscard]
    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    /**
     * Return a new Duration scaled by the given integer factor.
     *
     * @param int $factor Multiplier; negative values produce a negative duration.
     */
    #[NoDiscard]
    public function multipliedBy(int $factor): self
    {
        return new self($this->nanos * $factor);
    }

    /**
     * Return a new Duration divided by the given integer divisor (truncated toward zero).
     *
     * @param int $divisor Must be non-zero.
     */
    #[NoDiscard]
    public function dividedBy(int $divisor): self
    {
        return new self(intdiv($this->nanos, $divisor));
    }

    // -- Conversions ----------------------------------------------------------

    /**
     * Return the duration as a nanosecond count.
     */
    public function toNanos(): int
    {
        return $this->nanos;
    }

    /**
     * Return the duration truncated to whole microseconds.
     */
    public function toMicros(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_MICRO);
    }

    /**
     * Return the duration truncated to whole milliseconds.
     */
    public function toMillis(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_MILLI);
    }

    /**
     * Return the duration truncated to whole seconds.
     */
    public function toSeconds(): int
    {
        return intdiv($this->nanos, self::NANOS_PER_SECOND);
    }

    /**
     * Return the duration as a floating-point number of seconds.
     *
     * Prefer toNanos() / toMillis() in hot paths to avoid floating-point overhead.
     */
    public function toSecondsFloat(): float
    {
        return $this->nanos / self::NANOS_PER_SECOND;
    }

    // -- Comparisons ----------------------------------------------------------

    /**
     * Return true if this duration has the same nanosecond count as the other.
     */
    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    /**
     * Return true if this duration is strictly longer than the other.
     */
    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    /**
     * Return true if this duration is strictly shorter than the other.
     */
    public function isLessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    /**
     * Return true if this duration has a nanosecond count of exactly zero.
     */
    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    /**
     * Compare this duration to another, returning a negative int, zero, or positive int.
     *
     * Suitable for use with usort() or similar comparison callbacks.
     */
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
