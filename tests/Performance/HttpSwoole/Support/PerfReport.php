<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole\Support;

use RuntimeException;

use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function time;

use const JSON_PRETTY_PRINT;

/**
 * Writes a performance summary as JSON to disk for trend tracking.
 *
 * @psalm-api
 */
final class PerfReport
{
    /**
     * @param array<string, mixed> $summary
     */
    public static function write(string $testName, array $summary, ?string $resultsDir = null): string
    {
        $dir = $resultsDir ?? __DIR__ . '/../.results';

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Failed to create results directory: {$dir}");
        }

        $timestamp = (string) time();
        $path = "{$dir}/{$testName}.{$timestamp}.json";
        $payload = [
            'summary' => $summary,
            'test' => $testName,
            'timestamp' => $timestamp,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Failed to encode perf report as JSON');
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to write perf report to {$path}");
        }

        return $path;
    }
}
