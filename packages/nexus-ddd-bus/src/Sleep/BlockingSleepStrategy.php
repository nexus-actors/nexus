<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Sleep;

use Monadial\Duration\FiniteDuration;
use Override;

use function max;
use function usleep;

/**
 * @psalm-api
 *
 * Synchronous default that calls `usleep`. Safe under `Profile::Sync`
 * (request thread is what we own); unsafe under Async/Actor because it
 * blocks the worker — adopters supply a cooperative impl there. Negative
 * durations clamp to zero so a misbehaving backoff cannot pass `usleep`
 * a negative int.
 */
final class BlockingSleepStrategy implements SleepStrategy
{
    #[Override]
    public function sleep(FiniteDuration $duration): void
    {
        usleep(max(0, $duration->toMicros()));
    }
}
