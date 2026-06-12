<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\HttpSwooleThreads;

use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\FreePort;
use Monadial\Nexus\Tests\Performance\HttpSwoole\Support\LatencyRecorder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder so the performance-http-swoole-threads testsuite is non-empty
 * until Phase 19/20 land real thread-mode benchmarks. Verifies the shared
 * harness classes are reachable from this namespace.
 */
#[CoversNothing]
final class HarnessSmokeTest extends TestCase
{
    #[Test]
    public function shared_harness_classes_are_usable_from_threads_suite(): void
    {
        $recorder = new LatencyRecorder();
        $recorder->record(1_000);

        self::assertSame(1, $recorder->count());
        self::assertGreaterThan(0, FreePort::find());
    }
}
