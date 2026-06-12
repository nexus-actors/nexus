<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Server;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\App\SwooleCompiledHttpApp;
use Monadial\Nexus\Http\Server\Swoole\Threads\WebSocket\Message\WebSocketFramePush;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\ConnectionTable;

/**
 * @internal
 *
 * Per-worker-thread runtime state for SwooleThreadHttpServer. One instance
 * lives per worker thread and is captured by the server's event closures via
 * `use ($runtime)`, replacing the previous static arrays keyed by
 * spl_object_id($server).
 *
 * The WebSocket-only fields (connections, channels, threadId, routerSenders)
 * are populated only when WebSocket support is enabled at the config level.
 */
final class ThreadServerRuntime
{
    public ?ActorSystem $system = null;

    public CompiledHttpApp|SwooleCompiledHttpApp|null $app = null;

    public ?ConnectionTable $connections = null;

    public ?ChannelActorRegistry $channels = null;

    public ?int $threadId = null;

    /** @var array<int, callable(WebSocketFramePush): void> */
    public array $routerSenders = [];

    public function reset(): void
    {
        $this->system        = null;
        $this->app           = null;
        $this->connections   = null;
        $this->channels      = null;
        $this->threadId      = null;
        $this->routerSenders = [];
    }
}
