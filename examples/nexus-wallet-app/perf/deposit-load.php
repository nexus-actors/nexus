<?php

declare(strict_types=1);

/**
 * Concurrent-deposit load tester.
 *
 * Spins up N Swoole coroutines that each POST /wallet/deposit in a loop
 * for D seconds, then prints rps / latency percentiles.
 *
 * Usage:
 *   php perf/deposit-load.php <base-url> <token> <duration-s> <concurrency>
 *
 * Defaults:
 *   php perf/deposit-load.php http://localhost:8080 alice-token 30 32
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

use function Co\run;

$baseUrl = $argv[1] ?? 'http://localhost:8080';
$token = $argv[2] ?? 'alice-token';
$durationSecs = (int) ($argv[3] ?? '30');
$concurrency = (int) ($argv[4] ?? '32');

$urlParts = parse_url($baseUrl);
assert(is_array($urlParts) && isset($urlParts['host']));
$host = (string) $urlParts['host'];
$port = (int) ($urlParts['port'] ?? 80);

$body = (string) json_encode(['amount' => 1]);

printf("== nexus-wallet-app deposit-load ==\n");
printf("target:      %s\n", $baseUrl);
printf("token:       %s\n", $token);
printf("duration:    %ds\n", $durationSecs);
printf("concurrency: %d\n", $concurrency);
printf("\n");

run(static function () use ($host, $port, $token, $body, $durationSecs, $concurrency): void {
    $started = microtime(true);
    $deadline = $started + $durationSecs;

    /** @var list<float> $latencies */
    $latencies = [];
    $ok = 0;
    $errors = 0;

    $wg = new Coroutine\WaitGroup();

    for ($i = 0; $i < $concurrency; $i++) {
        $wg->add();
        Coroutine::create(static function () use (
            $host,
            $port,
            $token,
            $body,
            $deadline,
            &$latencies,
            &$ok,
            &$errors,
            $wg,
        ): void {
            $client = new Client($host, $port);
            $client->set(['timeout' => 5]);
            $client->setHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ]);

            while (microtime(true) < $deadline) {
                $reqStart = microtime(true);
                $okStatus = $client->post('/wallet/deposit', $body);
                $elapsed = microtime(true) - $reqStart;

                if ($okStatus && $client->statusCode === 200) {
                    $ok++;
                    $latencies[] = $elapsed * 1000.0;
                } else {
                    $errors++;
                }
            }

            $client->close();
            $wg->done();
        });
    }

    $wg->wait();

    $elapsed = microtime(true) - $started;
    $rps = $ok / $elapsed;

    sort($latencies);
    $count = count($latencies);

    $percentile = static function (array $sorted, float $p) use ($count): float {
        if ($count === 0) {
            return 0.0;
        }

        $idx = (int) floor($p * $count);

        return $sorted[min($idx, $count - 1)] ?? 0.0;
    };

    printf("results\n");
    printf("  elapsed:    %.2fs\n", $elapsed);
    printf("  ok:         %d\n", $ok);
    printf("  errors:     %d\n", $errors);
    printf("  rps:        %.0f\n", $rps);
    printf("  latency p50: %.2fms\n", $percentile($latencies, 0.50));
    printf("  latency p95: %.2fms\n", $percentile($latencies, 0.95));
    printf("  latency p99: %.2fms\n", $percentile($latencies, 0.99));
    printf("  latency max: %.2fms\n", $latencies[$count - 1] ?? 0.0);
});
