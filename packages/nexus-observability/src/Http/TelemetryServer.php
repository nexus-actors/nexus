<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Prometheus\PrometheusCollector;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @psalm-api
 *
 * Opt-in HTTP server exposing telemetry for a standalone (non-WorkerPool) Nexus system.
 *
 * Must be started inside a coroutine context. Never blocks.
 * The ActorSystem and SwooleRuntime run with zero knowledge of this server.
 *
 * Usage:
 *     $server = new TelemetryServer($system, $runtime, port: 9502);
 *     $server->start(); // non-blocking
 *
 * Endpoints:
 *     GET /status  — JSON actor hierarchy + runtime snapshot
 *     GET /metrics — Prometheus text format
 */
final class TelemetryServer
{
    public function __construct(
        private readonly ActorSystem $system,
        private readonly SwooleRuntime $runtime,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9502,
    ) {}

    public function start(): void
    {
        $server = new Server($this->host, $this->port, false, true);

        $server->handle('/status', function (Request $req, Response $res): void {
            $systemSnapshot  = $this->system->snapshot();
            $runtimeSnapshot = $this->runtime->snapshot();

            $res->header('Content-Type', 'application/json');
            $res->end(json_encode([
                'mode' => 'standalone',
                'runtime' => $runtimeSnapshot->toArray(),
                'system' => $systemSnapshot->toArray(),
            ], JSON_THROW_ON_ERROR));
        });

        $server->handle('/metrics', function (Request $req, Response $res): void {
            $collector = new PrometheusCollector();
            $collector->collect($this->system->snapshot(), $this->runtime->snapshot());

            $res->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $res->end($collector->render());
        });

        Coroutine::create(static function () use ($server): void {
            $server->start();
        });
    }
}
