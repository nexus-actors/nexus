<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 *
 * Per-exception mapping of throwable type → BackoffStrategy.
 * Built via RetryPolicyBuilder. Implements BackoffStrategy itself so it can be
 * passed anywhere a strategy is expected.
 */
final readonly class RetryPolicy implements BackoffStrategy
{
    /**
     * @param array<class-string<Throwable>, BackoffStrategy> $handlers
     * @param array<class-string<Throwable>, true> $giveUpSet
     */
    public function __construct(
        public array $handlers,
        public array $giveUpSet,
    ) {}

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        foreach ($this->giveUpSet as $cls => $_) {
            if ($cause instanceof $cls) {
                return Option::none();
            }
        }
        foreach ($this->handlers as $cls => $strategy) {
            if ($cause instanceof $cls) {
                return $strategy->delayFor($attempt, $cause);
            }
        }

        return Option::none();
    }
}
