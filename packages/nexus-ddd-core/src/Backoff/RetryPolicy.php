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

    /** @return Option<\Monadial\Duration\Duration> */
    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        $shouldGiveUp = array_any(
            array_keys($this->giveUpSet),
            static fn(string $cls): bool => $cause instanceof $cls,
        );

        if ($shouldGiveUp) {
            return Option::none();
        }

        /** @var BackoffStrategy|null $strategy */
        $strategy = array_find(
            $this->handlers,
            static fn(BackoffStrategy $_, string $cls): bool => $cause instanceof $cls,
        );

        return $strategy?->delayFor($attempt, $cause) ?? Option::none();
    }
}
