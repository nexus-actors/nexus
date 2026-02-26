<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole;

use Closure;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Override;
use Swoole\Coroutine\Channel;

/**
 * Swoole-based FutureSlot using a Channel(1) for coroutine suspension.
 *
 * Blocks indefinitely on await(). The caller schedules a timeout timer
 * that calls fail() to unblock with an exception.
 *
 * @implements FutureSlot<object>
 */
final class SwooleFutureSlot implements FutureSlot
{
    private readonly Channel $channel;
    private ?object $result = null;
    private ?FutureException $failure = null;
    private bool $resolved = false;
    private bool $cancelled = false;

    /** @var list<Closure(): void> */
    private array $cancelCallbacks = [];

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
    public function fail(FutureException $e): void
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
    public function cancel(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->cancelled = true;
        $this->resolved = true;

        foreach ($this->cancelCallbacks as $callback) {
            $callback();
        }

        $this->channel->push(false);
    }

    #[Override]
    public function onCancel(Closure $callback): void
    {
        $this->cancelCallbacks[] = $callback;
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

        if ($this->cancelled) {
            throw new FutureCancelledException();
        }

        assert($this->result !== null);

        return $this->result;
    }
}
