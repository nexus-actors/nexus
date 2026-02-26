<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Supervision;

use Closure;
use Monadial\Nexus\Runtime\Duration;
use Throwable;

/**
 * @psalm-api
 *
 * Immutable supervision strategy configuration.
 *
 * Created via named constructors: oneForOne(), allForOne(), exponentialBackoff().
 */
final readonly class SupervisionStrategy
{
    /**
     * @param Closure(Throwable): Directive $decider
     */
    private function __construct(
        public StrategyType $type,
        public int $maxRetries,
        public Duration $window,
        public Closure $decider,
        public Duration $initialBackoff,
        public Duration $maxBackoff,
        public float $multiplier,
    ) {}

    /**
     * One-for-one strategy: only the failed child is acted upon.
     *
     * @param (Closure(Throwable): Directive)|null $decider
     */
    public static function oneForOne(int $maxRetries = 3, ?Duration $window = null, ?Closure $decider = null): self
    {
        return new self(
            type: StrategyType::OneForOne,
            maxRetries: $maxRetries,
            window: $window ?? Duration::seconds(60),
            decider: $decider ?? self::defaultDecider(),
            initialBackoff: Duration::zero(),
            maxBackoff: Duration::zero(),
            multiplier: 1.0,
        );
    }

    /**
     * All-for-one strategy: all children are acted upon when one fails.
     *
     * @param (Closure(Throwable): Directive)|null $decider
     */
    public static function allForOne(int $maxRetries = 3, ?Duration $window = null, ?Closure $decider = null): self
    {
        return new self(
            type: StrategyType::AllForOne,
            maxRetries: $maxRetries,
            window: $window ?? Duration::seconds(60),
            decider: $decider ?? self::defaultDecider(),
            initialBackoff: Duration::zero(),
            maxBackoff: Duration::zero(),
            multiplier: 1.0,
        );
    }

    /**
     * Exponential backoff strategy: restarts with increasing delays.
     *
     * @param (Closure(Throwable): Directive)|null $decider
     */
    public static function exponentialBackoff(
        Duration $initialBackoff,
        Duration $maxBackoff,
        int $maxRetries = 3,
        float $multiplier = 2.0,
        ?Closure $decider = null,
    ): self {
        return new self(
            type: StrategyType::ExponentialBackoff,
            maxRetries: $maxRetries,
            window: Duration::zero(),
            decider: $decider ?? self::defaultDecider(),
            initialBackoff: $initialBackoff,
            maxBackoff: $maxBackoff,
            multiplier: $multiplier,
        );
    }

    /**
     * Invoke the decider to determine the directive for the given exception.
     */
    public function decide(Throwable $exception): Directive
    {
        return ($this->decider)($exception);
    }

    /**
     * @return Closure(Throwable): Directive
     */
    private static function defaultDecider(): Closure
    {
        return static fn(Throwable $_): Directive => Directive::Restart;
    }
}
