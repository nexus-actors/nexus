<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Ws\CompiledApplication;

/**
 * @internal
 *
 * Per-worker-thread runtime state for SwooleThreadServer. One instance
 * lives per worker thread and is captured by the server's event closures
 * via `use ($runtime)`.
 */
final class ThreadServerRuntime
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
