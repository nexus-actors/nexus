<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Ws\CompiledApplication;

/**
 * @internal
 *
 * Per-worker (process or thread) runtime state. Captured by the Swoole event
 * closures via `use ($runtime)`; `system` and `app` are populated by
 * WorkerStart and consumed by Request/Open/Message/Close/WorkerStop.
 * `failureBucket` is a sliding-window counter for the boot circuit breaker.
 */
abstract class ServerRuntime
{
    public ?ActorSystem $system = null;

    public ?CompiledApplication $app = null;

    /** @var array{count: int, since: float} */
    public array $failureBucket = ['count' => 0, 'since' => 0.0];

    public function reset(): void
    {
        $this->system = null;
        $this->app = null;
    }
}
