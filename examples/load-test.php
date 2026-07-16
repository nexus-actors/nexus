<?php

declare(strict_types=1);

/**
 * Swoole coroutine HTTP + WebSocket load test for the thread-server example.
 *
 * Usage:
 *   docker compose exec php-swoole php examples/load-test.php \
 *       --url=http://127.0.0.1:8080/hello/loadtest \
 *       --concurrency=100 \
 *       --requests=10000
 *
 *   docker compose exec php-swoole php examples/load-test.php \
 *       --ws=ws://127.0.0.1:8080/ws/echo \
 *       --concurrency=50 \
 *       --requests=5000
 *
 * Options:
 *   --url=URL              HTTP GET target
 *   --ws=URL               WebSocket upgrade target; echoes "payload" through
 *   --concurrency=N        Number of parallel coroutines (default 50)
 *   --requests=N           Total requests across all coroutines (default 1000)
 *   --payload=STRING       Per-request payload for WS mode (default "ping")
 *   --warmup=N             Discard the first N requests' samples (default 0)
 *
 * Reports: requests, duration, RPS, p50/p95/p99/avg/max latency, error count.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\WebSocket\Frame;

use function Co\run;

$opts = getopt('', [
    'concurrency::',
    'payload::',
    'requests::',
    'url::',
    'warmup::',
    'ws::',
]);

$scalar = static fn(mixed $v): string => is_array($v)
    ? (string) end($v)
    : (string) ($v ?? '');
$httpUrl = isset($opts['url'])
    ? $scalar($opts['url'])
    : null;
$wsUrl = isset($opts['ws'])
    ? $scalar($opts['ws'])
    : null;
$concurrency = (int) $scalar($opts['concurrency'] ?? 50);
$total = (int) $scalar($opts['requests'] ?? 1000);
$payload = $scalar($opts['payload'] ?? 'ping');
$warmup = (int) $scalar($opts['warmup'] ?? 0);

if (($httpUrl === null && $wsUrl === null) || ($httpUrl !== null && $wsUrl !== null)) {
    fwrite(STDERR, "Pass exactly one of --url=HTTP or --ws=WS.\n");
    exit(1);
}

if ($concurrency < 1 || $total < 1) {
    fwrite(STDERR, "--concurrency and --requests must be >= 1.\n");
    exit(1);
}

$target = $httpUrl ?? $wsUrl;
$parts = parse_url($target);

if ($parts === false) {
    fwrite(STDERR, "Invalid URL: {$target}\n");
    exit(1);
}

$scheme = $parts['scheme'] ?? 'http';
$host = $parts['host'] ?? '127.0.0.1';
$port = $parts['port'] ?? ($scheme === 'https' || $scheme === 'wss' ? 443 : 80);
$path = $parts['path'] ?? '/';
$mode = $wsUrl !== null
    ? 'WS'
    : 'HTTP';

printf(
    "Load test: %s %s:%d%s concurrency=%d requests=%d warmup=%d\n",
    $mode,
    $host,
    $port,
    $path,
    $concurrency,
    $total,
    $warmup,
);

/** @var list<int> $latencies */
$latencies = [];
$errors = 0;
$counter = 0;

$start = hrtime(true);

$completed = (bool) run(
    static function () use ($host, $port, $path, $concurrency, $total, $warmup, $payload, $mode, &$latencies, &$errors, &$counter): void {
        $perWorker = (int) ceil($total / $concurrency);

        $done = new Channel($concurrency);

        for ($w = 0; $w < $concurrency; $w++) {
            Coroutine::create(static function () use (
                $host,
                $port,
                $path,
                $perWorker,
                $total,
                $warmup,
                $payload,
                $mode,
                &$latencies,
                &$errors,
                &$counter,
                $done,
            ): void {
                for ($i = 0; $i < $perWorker; $i++) {
                    $idx = ++$counter;

                    if ($idx > $total) {
                        break;
                    }

                    $t0 = hrtime(true);
                    $ok = $mode === 'WS'
                        ? wsRoundTrip($host, $port, $path, $payload)
                        : httpGet($host, $port, $path);
                    $elapsed = (int) (hrtime(true) - $t0);

                    if (!$ok) {
                        $errors++;

                        continue;
                    }

                    if ($idx > $warmup) {
                        $latencies[] = $elapsed;
                    }
                }

                $done->push(true);
            });
        }

        // Wait for every worker coroutine to finish.
        for ($w = 0; $w < $concurrency; $w++) {
            $done->pop();
        }
    },
);

if (!$completed) {
    fwrite(STDERR, "Coroutine scheduler failed.\n");
    exit(1);
}

$end = hrtime(true);
$durationNs = $end - $start;
$durationSec = $durationNs / 1_000_000_000;
$count = count($latencies);
$rps = $count > 0
    ? (float) $count / (float) $durationSec
    : 0.0;

sort($latencies);
$p = static fn(int $percentile): int => $count === 0
    ? 0
    : $latencies[(int) min(
        $count - 1,
        floor($percentile * $count / 100),
    )];
$avg = $count === 0
    ? 0
    : (int) (array_sum($latencies) / $count);
$max = $count === 0
    ? 0
    : (int) end($latencies);

printf(
    "\nResult:\n"
    . "  samples            %d (errors %d, warmup discarded %d)\n"
    . "  total duration     %.3f s\n"
    . "  throughput         %.1f req/s\n"
    . "  latency avg        %s\n"
    . "  latency p50        %s\n"
    . "  latency p95        %s\n"
    . "  latency p99        %s\n"
    . "  latency max        %s\n",
    $count,
    $errors,
    $warmup,
    $durationSec,
    $rps,
    fmtNs($avg),
    fmtNs($p(50)),
    fmtNs($p(95)),
    fmtNs($p(99)),
    fmtNs($max),
);

function httpGet(string $host, int $port, string $path): bool
{
    $c = new HttpClient($host, $port);
    $c->set(['timeout' => 5.0]);
    $ok = (bool) $c->get($path);
    $status = (int) $c->statusCode;
    $c->close();

    return $ok && $status >= 200 && $status < 400;
}

function wsRoundTrip(string $host, int $port, string $path, string $payload): bool
{
    $c = new HttpClient($host, $port);
    $c->set(['timeout' => 5.0]);

    if (!$c->upgrade($path)) {
        $c->close();

        return false;
    }

    if (!$c->push($payload)) {
        $c->close();

        return false;
    }

    /** @var Frame|false|null $frame — Swoole's native return type is invisible to Psalm */
    $frame = $c->recv(2.0);
    $c->close();

    return $frame !== false && $frame !== null;
}

function fmtNs(int $ns): string
{
    if ($ns < 1_000) {
        return $ns . ' ns';
    }

    if ($ns < 1_000_000) {
        return sprintf('%.2f µs', $ns / 1_000);
    }

    if ($ns < 1_000_000_000) {
        return sprintf('%.2f ms', $ns / 1_000_000);
    }

    return sprintf('%.2f s', $ns / 1_000_000_000);
}
