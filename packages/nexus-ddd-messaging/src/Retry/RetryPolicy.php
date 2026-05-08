<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Composite first-match-wins policy. Checks `giveUpSet` first; if the
 * exception class (or any parent) appears there, returns None immediately.
 * Otherwise walks `handlers` in insertion order and delegates to the first
 * entry whose key is a supertype of the thrown exception.
 *
 * @psalm-type ExceptionClass = class-string<Throwable>
 */
final readonly class RetryPolicy implements BackoffStrategy
{
    /**
     * @param array<class-string<Throwable>, BackoffStrategy> $handlers  Ordered map of exception type → strategy.
     * @param array<class-string<Throwable>, true>            $giveUpSet Exception types that must never be retried.
     */
    public function __construct(private array $handlers, private array $giveUpSet,) {}

    /**
     * @return Option<FiniteDuration>
     */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        foreach ($this->giveUpSet as $class => $_) {
            if ($cause instanceof $class) {
                return Option::none();
            }
        }

        foreach ($this->handlers as $class => $strategy) {
            if ($cause instanceof $class) {
                return $strategy->delayFor($attempt, $cause);
            }
        }

        return Option::none();
    }
}
