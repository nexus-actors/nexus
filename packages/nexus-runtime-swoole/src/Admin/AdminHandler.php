<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Admin;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Timer;

/**
 * HTTP request handler for the Swoole admin API.
 *
 * Provides introspection endpoints compatible with the Swoole Admin API response format.
 * All responses use the structure: {"code": 0, "data": ...} for success,
 * {"code": 4004, "data": "error message"} for unknown commands.
 *
 * @psalm-api
 */
final class AdminHandler
{
    private readonly int $cpuCount;

    private float $prevCpuSystem = 0.0;

    private float $prevCpuTime = 0.0;

    private float $prevCpuUser = 0.0;

    private readonly float $startedAt;

    public function __construct()
    {
        $this->cpuCount = $this->detectCpuCount();
        $this->startedAt = microtime(true);
        $this->sampleCpuTicks();
    }

    public function handle(Request $request, Response $response): void
    {
        $response->header('Access-Control-Allow-Headers', 'Content-Type');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Content-Type', 'application/json');

        if ($request->getMethod() === 'OPTIONS') {
            $response->status(204);
            $response->end('');

            return;
        }

        /** @var array<string, string> $server */
        $server = $request->server;
        $path = rtrim($server['request_uri'] ?? '', '/');
        $command = basename($path);
        $params = $this->parseParams($request);

        $result = $this->dispatch($command, $params);

        $response->end(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Dispatch a command and return the response array.
     *
     * @param array<string, mixed> $params
     *
     * @return array{code: int, data: mixed}
     */
    public function dispatch(string $command, array $params = []): array
    {
        return match ($command) {
            'gc_status' => $this->success($this->gcStatus()),
            'get_coroutine_list' => $this->success($this->getCoroutineList()),
            'get_declared_classes' => $this->success($this->getDeclaredClasses()),
            'get_included_files' => $this->success($this->getIncludedFiles()),
            'get_loaded_extensions' => $this->success($this->getLoadedExtensions()),
            'get_server_cpu_usage' => $this->success($this->getServerCpuUsage()),
            'get_server_memory_usage' => $this->success($this->getServerMemoryUsage()),
            'get_timer_list' => $this->success($this->getTimerList()),
            'get_version_info' => $this->success($this->getVersionInfo()),
            'get_worker_info' => $this->success($this->getWorkerInfo()),
            'multi' => $this->handleMulti($params),
            'server_stats' => $this->success($this->serverStats()),
            default => $this->error("Unknown command: {$command}"),
        };
    }

    /**
     * @return array{php: string, swoole: string, os: string, extensions: list<string>}
     */
    private function getVersionInfo(): array
    {
        return [
            'extensions' => get_loaded_extensions(),
            'os' => PHP_OS,
            'php' => PHP_VERSION,
            'swoole' => swoole_version(),
        ];
    }

    /**
     * @psalm-suppress InvalidOperand
     *
     * @return array{cpu_count: int, system: float, total: float, user: float}
     */
    private function getServerCpuUsage(): array
    {
        $sample = $this->sampleCpuTicks();

        if ($sample === null) {
            return [
                'cpu_count' => $this->cpuCount,
                'system' => 0.0,
                'total' => 0.0,
                'user' => 0.0,
            ];
        }

        [$userTicks, $systemTicks, $wallTime] = $sample;

        $deltaUser = $userTicks - $this->prevCpuUser;
        $deltaSystem = $systemTicks - $this->prevCpuSystem;
        $deltaWall = $wallTime - $this->prevCpuTime;

        $this->prevCpuUser = $userTicks;
        $this->prevCpuSystem = $systemTicks;
        $this->prevCpuTime = $wallTime;

        if ($deltaWall <= 0.0) {
            return [
                'cpu_count' => $this->cpuCount,
                'system' => 0.0,
                'total' => 0.0,
                'user' => 0.0,
            ];
        }

        $userPct = round($deltaUser / $deltaWall * 100, 2);
        $systemPct = round($deltaSystem / $deltaWall * 100, 2);

        return [
            'cpu_count' => $this->cpuCount,
            'system' => $systemPct,
            'total' => round($userPct + $systemPct, 2),
            'user' => $userPct,
        ];
    }

    /**
     * @return array{memory_get_peak_usage: int, memory_get_peak_usage_real: int, memory_get_usage: int, memory_get_usage_real: int}
     */
    private function getServerMemoryUsage(): array
    {
        return [
            'memory_get_peak_usage' => memory_get_peak_usage(),
            'memory_get_peak_usage_real' => memory_get_peak_usage(true),
            'memory_get_usage' => memory_get_usage(),
            'memory_get_usage_real' => memory_get_usage(true),
        ];
    }

    /**
     * @return array{coroutine: array<string, mixed>, pid: int, uptime_seconds: float}
     */
    private function serverStats(): array
    {
        $pid = getmypid();

        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'coroutine' => $coroutineStats,
            'pid' => $pid !== false ? $pid : 0,
            'uptime_seconds' => round(microtime(true) - $this->startedAt, 3),
        ];
    }

    /**
     * @return array{coroutine_num: int, memory_usage: int, pid: int, timer_num: int}
     */
    private function getWorkerInfo(): array
    {
        /** @var array<string, int> $stats */
        $stats = Coroutine::stats();

        /** @var array<string, int> $timerStats */
        $timerStats = Timer::stats();
        $pid = getmypid();

        return [
            'coroutine_num' => $stats['coroutine_num'] ?? 0,
            'memory_usage' => memory_get_usage(),
            'pid' => $pid !== false ? $pid : 0,
            'timer_num' => $timerStats['num'] ?? 0,
        ];
    }

    /**
     * @psalm-suppress MixedArrayAccess, MixedAssignment, MixedOperand, InvalidOperand
     *
     * @return array{coroutines: list<array<string, mixed>>, count: int}
     */
    private function getCoroutineList(): array
    {
        $coroutines = [];

        /** @var int $cid */
        foreach (Coroutine::list() as $cid) {
            $rawTrace = Coroutine::getBackTrace($cid, DEBUG_BACKTRACE_IGNORE_ARGS);
            $frames = [];

            if (is_array($rawTrace)) {
                foreach ($rawTrace as $i => $frame) {
                    /** @var array<string, string> $frame */
                    $location = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
                    $call = isset($frame['class'])
                        ? $frame['class'] . ($frame['type'] ?? '::') . $frame['function']
                        : ($frame['function'] ?? '?');

                    $frames[] = "#{$i} {$location} {$call}()";
                }
            }

            $coroutines[] = [
                'cid' => $cid,
                'elapsed_ms' => round((float) Coroutine::getElapsed($cid) / 1000, 3),
                'stack_trace' => $frames,
                'stack_usage' => Coroutine::getStackUsage($cid),
            ];
        }

        return [
            'coroutines' => $coroutines,
            'count' => count($coroutines),
        ];
    }

    /**
     * @return array{count: int, timers: list<array<string, mixed>>}
     */
    private function getTimerList(): array
    {
        $timers = [];

        /** @var int $timerId */
        foreach (Timer::list() as $timerId) {
            /** @var array<string, int>|false $info */
            $info = Timer::info($timerId);

            if ($info !== false) {
                $timers[] = [
                    'exec_count' => $info['exec_count'] ?? 0,
                    'exec_msec' => $info['exec_msec'] ?? 0,
                    'id' => $timerId,
                    'interval' => $info['interval'] ?? 0,
                    'round' => $info['round'] ?? 0,
                ];
            }
        }

        return [
            'count' => count($timers),
            'timers' => $timers,
        ];
    }

    /**
     * @return array{collected: int, runs: int}
     */
    private function gcStatus(): array
    {
        $status = gc_status();

        return [
            'collected' => $status['collected'] ?? 0,
            'runs' => $status['runs'] ?? 0,
        ];
    }

    /**
     * @return array{count: int, files: list<string>}
     */
    private function getIncludedFiles(): array
    {
        $files = get_included_files();

        return [
            'count' => count($files),
            'files' => $files,
        ];
    }

    /**
     * @return array{count: int, extensions: list<string>}
     */
    private function getLoadedExtensions(): array
    {
        $extensions = get_loaded_extensions();

        return [
            'count' => count($extensions),
            'extensions' => $extensions,
        ];
    }

    /**
     * @return array{classes: list<string>, count: int}
     */
    private function getDeclaredClasses(): array
    {
        $classes = get_declared_classes();

        return [
            'classes' => $classes,
            'count' => count($classes),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{code: int, data: mixed}
     */
    private function handleMulti(array $params): array
    {
        if (!isset($params['commands']) || !is_array($params['commands'])) {
            return $this->error('multi requires a "commands" parameter with an array of command names');
        }

        $results = [];

        /** @var mixed $command */
        foreach ($params['commands'] as $command) {
            if (is_string($command)) {
                $results[] = $this->dispatch($command);
            }
        }

        return $this->success($results);
    }

    /**
     * @return array{code: int, data: mixed}
     */
    private function success(mixed $data): array
    {
        return ['code' => 0, 'data' => $data];
    }

    /**
     * @return array{code: int, data: string}
     */
    private function error(string $message): array
    {
        return ['code' => 4004, 'data' => $message];
    }

    /**
     * Read user/system CPU ticks and wall clock from /proc/self/stat.
     *
     * Returns [userSeconds, systemSeconds, wallTimeSeconds] or null on failure.
     *
     * @psalm-suppress InvalidOperand
     *
     * @return array{float, float, float}|null
     */
    private function sampleCpuTicks(): ?array
    {
        $stat = @file_get_contents('/proc/self/stat');

        if ($stat === false) {
            return null;
        }

        $fields = explode(' ', $stat);

        if (count($fields) < 15) {
            return null;
        }

        // Fields 13 (utime) and 14 (stime) are in clock ticks
        $clkTck = 100.0; // sysconf(_SC_CLK_TCK), virtually always 100 on Linux
        $userTicks = (float) $fields[13] / $clkTck;
        $systemTicks = (float) $fields[14] / $clkTck;

        return [$userTicks, $systemTicks, microtime(true)];
    }

    /**
     * @psalm-suppress MixedReturnStatement, MixedInferredReturnType
     */
    private function detectCpuCount(): int
    {
        if (function_exists('swoole_cpu_num')) {
            /** @var int */
            return swoole_cpu_num();
        }

        $nproc = @file_get_contents('/proc/cpuinfo');

        if ($nproc !== false) {
            return max(1, substr_count($nproc, 'processor'));
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParams(Request $request): array
    {
        /** @var array<string, mixed> $params */
        $params = $request->get ?? [];

        if ($request->getMethod() === 'POST') {
            /** @var string $body */
            $body = $request->getContent();

            if ($body !== '') {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($body, true);

                if (is_array($decoded)) {
                    $params = array_merge($params, $decoded);
                }
            }
        }

        return $params;
    }
}
