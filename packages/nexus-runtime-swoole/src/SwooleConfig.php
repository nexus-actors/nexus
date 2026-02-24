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
        public string $adminHost = '127.0.0.1',
        public int $adminPort = 0,
        public int $defaultMailboxCapacity = 1000,
        public bool $enableCoroutineHook = true,
        public int $maxCoroutines = 100_000,
    ) {}

    public function withAdminHost(string $host): self
    {
        return clone($this, ['adminHost' => $host]);
    }

    public function withAdminPort(int $port): self
    {
        return clone($this, ['adminPort' => $port]);
    }

    public function withDefaultMailboxCapacity(int $capacity): self
    {
        return clone($this, ['defaultMailboxCapacity' => $capacity]);
    }

    public function withEnableCoroutineHook(bool $enable): self
    {
        return clone($this, ['enableCoroutineHook' => $enable]);
    }

    public function withMaxCoroutines(int $max): self
    {
        return clone($this, ['maxCoroutines' => $max]);
    }
}
