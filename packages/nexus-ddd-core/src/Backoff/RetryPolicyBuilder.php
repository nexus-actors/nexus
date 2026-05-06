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

    public static function create(): self
    {
        return new self();
    }

    /** @param class-string<Throwable> $exceptionClass */
    public function onException(string $exceptionClass, BackoffStrategy $strategy): self
    {
        $this->handlers[$exceptionClass] = $strategy;

        return $this;
    }

    /** @param class-string<Throwable> $exceptionClass */
    public function giveUpOn(string $exceptionClass): self
    {
        $this->giveUpSet[$exceptionClass] = true;

        return $this;
    }

    public function build(): RetryPolicy
    {
        return new RetryPolicy($this->handlers, $this->giveUpSet);
    }
}
