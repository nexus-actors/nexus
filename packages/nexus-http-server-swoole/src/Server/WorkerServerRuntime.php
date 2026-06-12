<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;

/**
 * @internal
 *
 * Per-worker-process runtime state for SwooleWorkerHttpServer. One instance
 * lives per worker process and is captured by the server's event closures via
 * `use ($runtime)`, replacing the previous static arrays keyed by
 * spl_object_id($server).
 *
 * The ChannelActorRegistry and ConnectionTable are only constructed when
 * WebSocket support is enabled at the config level — they remain null in
 * pure-HTTP mode.
 */
final class WorkerServerRuntime
{
    public ?ActorSystem $system = null;

    public CompiledHttpApp|SwooleCompiledHttpApp|null $app = null;

    public ?ConnectionTable $connections = null;

    public ?ChannelActorRegistry $channels = null;

    /** @var array{count: int, since: float} */
    public array $failureBucket = ['count' => 0, 'since' => 0.0];

    public function reset(): void
    {
        $this->system      = null;
        $this->app         = null;
        $this->connections = null;
        $this->channels    = null;
    }
}
