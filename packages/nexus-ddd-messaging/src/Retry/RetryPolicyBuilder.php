<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Retry;

use NoDiscard;
use Throwable;

/**
 * @psalm-api
 *
 * Fluent builder for `RetryPolicy`. Each mutator returns a new instance;
 * the original is unmodified.
 */
final class RetryPolicyBuilder
{
    /**
     * @param array<class-string<Throwable>, BackoffStrategy> $handlers
     * @param array<class-string<Throwable>, true>            $giveUpSet
     */
    private function __construct(
        private readonly array $handlers,
        private readonly array $giveUpSet,
    ) {}

    public static function create(): self
    {
        return new self(handlers: [], giveUpSet: []);
    }

    /**
     * @param class-string<Throwable> $exceptionClass
     */
    #[NoDiscard('returns new builder — original is unmodified')]
    public function onException(string $exceptionClass, BackoffStrategy $strategy): self
    {
        return new self(
            handlers: $this->handlers + [$exceptionClass => $strategy],
            giveUpSet: $this->giveUpSet,
        );
    }

    /**
     * @param class-string<Throwable> $exceptionClass
     */
    #[NoDiscard('returns new builder — original is unmodified')]
    public function giveUpOn(string $exceptionClass): self
    {
        return new self(
            handlers: $this->handlers,
            giveUpSet: $this->giveUpSet + [$exceptionClass => true],
        );
    }

    #[NoDiscard('returns the constructed RetryPolicy')]
    public function build(): RetryPolicy
    {
        return new RetryPolicy(
            handlers: $this->handlers,
            giveUpSet: $this->giveUpSet,
        );
    }
}
