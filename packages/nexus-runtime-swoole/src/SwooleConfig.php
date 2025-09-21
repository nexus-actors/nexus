<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class SwooleConfig
{
    public function __construct(
        public int $defaultMailboxCapacity = 1000,
        public bool $enableCoroutineHook = true,
        public int $maxCoroutines = 100_000,
    ) {}

    public function withDefaultMailboxCapacity(int $capacity): self
    {
        return new self($capacity, $this->enableCoroutineHook, $this->maxCoroutines);
    }

    public function withEnableCoroutineHook(bool $enable): self
    {
        return new self($this->defaultMailboxCapacity, $enable, $this->maxCoroutines);
    }

    public function withMaxCoroutines(int $max): self
    {
        return new self($this->defaultMailboxCapacity, $this->enableCoroutineHook, $max);
    }
}
