<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Internal\Pipeline;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyReservation;

/**
 * @internal
 *
 * Per-dispatch scratchpad. Asymmetric visibility (PHP 8.4) keeps reads
 * public while writes go through dedicated methods.
 *
 * NOT a value object — short-lived, mutable. One per dispatch.
 *
 * @psalm-suppress UnusedClass
 *   Phase 9 ships the type; middlewares in Phase 10 consume it.
 */
final class PipelineContext
{
    /** @var Option<IdempotencyReservation> */
    public private(set) Option $idempotencyReservation;

    public private(set) int $causationDepth = 0;

    public private(set) int $retryAttempt = 0;

    public function __construct()
    {
        $this->idempotencyReservation = Option::none();
    }

    public function rememberReservation(IdempotencyReservation $reservation): void
    {
        $this->idempotencyReservation = Option::some($reservation);
    }

    public function setCausationDepth(int $depth): void
    {
        $this->causationDepth = $depth;
    }

    public function incrementRetryAttempt(): void
    {
        $this->retryAttempt++;
    }
}
