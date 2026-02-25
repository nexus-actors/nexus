<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Monadial\Nexus\Core\Actor\FutureSlot;
use Override;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Swoole-based FutureSlot using a Channel(1) for coroutine suspension.
 *
 * Blocks indefinitely on await(). The caller schedules a timeout timer
 * that calls fail() to unblock with an exception.
 */
final class SwooleFutureSlot implements FutureSlot
{
    private readonly Channel $channel;
    private ?object $result = null;
    private ?Throwable $failure = null;
    private bool $resolved = false;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    #[Override]
    public function resolve(object $value): void
    {
        if ($this->resolved) {
            return;
        }

        $this->result = $value;
        $this->resolved = true;
        $this->channel->push(true);
    }

    #[Override]
    public function fail(Throwable $e): void
    {
        if ($this->resolved) {
            return;
        }

        $this->failure = $e;
        $this->resolved = true;
        $this->channel->push(false);
    }

    #[Override]
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    #[Override]
    public function await(): object
    {
        if (!$this->resolved) {
            $this->channel->pop(-1);
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        assert($this->result !== null);

        return $this->result;
    }
}
