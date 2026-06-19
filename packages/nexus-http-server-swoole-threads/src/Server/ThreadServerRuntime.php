<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Http\Server\Swoole\Bridge\ServerRuntime;

/**
 * @internal
 *
 * Per-worker-thread runtime state for SwooleThreadServer. One instance
 * lives per worker thread and is captured by the server's event closures
 * via `use ($runtime)`.
 */
final class ThreadServerRuntime extends ServerRuntime {}
