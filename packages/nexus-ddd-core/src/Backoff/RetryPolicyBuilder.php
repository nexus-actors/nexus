<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Throwable;

/** @psalm-api */
final class RetryPolicyBuilder
{
    /** @var array<class-string<Throwable>, BackoffStrategy> */
    private array $handlers = [];

    /** @var array<class-string<Throwable>, true> */
    private array $giveUpSet = [];

    #[\NoDiscard('create() returns the builder — ignoring it produces no policy')]
    public static function create(): self
    {
        return new self();
    }

    /** @param class-string<Throwable> $exceptionClass */
    #[\NoDiscard('fluent builder; the returned $this is required to continue the chain')]
    public function onException(string $exceptionClass, BackoffStrategy $strategy): self
    {
        $this->handlers[$exceptionClass] = $strategy;

        return $this;
    }

    /** @param class-string<Throwable> $exceptionClass */
    #[\NoDiscard('fluent builder; the returned $this is required to continue the chain')]
    public function giveUpOn(string $exceptionClass): self
    {
        $this->giveUpSet[$exceptionClass] = true;

        return $this;
    }

    #[\NoDiscard('build() returns the constructed RetryPolicy — discarding it loses the configuration')]
    public function build(): RetryPolicy
    {
        return new RetryPolicy($this->handlers, $this->giveUpSet);
    }
}
