<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwoole;

use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\FreePort;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\PerfReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function range;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversNothing]
final class HarnessSmokeTest extends TestCase
{
    #[Test]
    public function recorder_computes_percentiles(): void
    {
        $recorder = new LatencyRecorder();

        foreach (range(1, 100) as $n) {
            $recorder->record($n * 1000);
        }

        // Percentile uses nearest-rank with floor(n * p): index 50/95/99 of sorted
        // samples 1..100 * 1000 ns yields 51_000 / 96_000 / 100_000.
        self::assertSame(100, $recorder->count());
        self::assertSame(1_000, $recorder->min());
        self::assertSame(100_000, $recorder->max());
        self::assertSame(51_000, $recorder->p50());
        self::assertSame(96_000, $recorder->p95());
        self::assertSame(100_000, $recorder->p99());
    }

    #[Test]
    public function recorder_summary_is_empty_safe(): void
    {
        $recorder = new LatencyRecorder();

        self::assertSame(
            ['count' => 0, 'max' => 0, 'min' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0],
            $recorder->summary(),
        );
    }

    #[Test]
    public function perf_report_writes_json_file(): void
    {
        $dir = sys_get_temp_dir() . '/nexus-perf-' . uniqid('', true);
        $path = PerfReport::write('smoke-test', ['count' => 1, 'p99' => 42], $dir);

        self::assertFileExists($path);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    #[Test]
    public function free_port_returns_usable_port(): void
    {
        $port = FreePort::find();

        self::assertGreaterThan(0, $port);
        self::assertLessThan(65_536, $port);
    }
}
