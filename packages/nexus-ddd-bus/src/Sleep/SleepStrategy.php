<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Sleep;

use Monadial\Duration\FiniteDuration;

/**
 * @psalm-api
 *
 * Pluggable sleep mechanism for middleware that needs to wait between
 * attempts (e.g. `OccRetryMiddleware` between OCC retries). Under
 * `Profile::Sync` the blocking impl is acceptable; under `Profile::Async`
 * or `Profile::Actor` adopters supply a cooperative impl (Swoole sleep,
 * fiber suspend) so the worker thread is not blocked.
 */
interface SleepStrategy
{
    public function sleep(FiniteDuration $duration): void;
}
