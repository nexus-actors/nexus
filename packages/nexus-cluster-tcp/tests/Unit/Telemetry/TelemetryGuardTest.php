<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Telemetry;

use Monadial\Nexus\Cluster\Tcp\Telemetry\TelemetryGuard;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TelemetryGuard::class)]
final class TelemetryGuardTest extends TestCase
{
    #[Test]
    public function safelySwallowsThrowables(): void
    {
        $guard = new TelemetryGuard();

        $guard->safely(static fn() => throw new RuntimeException('telemetry broke'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function safelyRunsTheCallable(): void
    {
        $guard = new TelemetryGuard();
        $ran = false;

        $guard->safely(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    #[Test]
    public function startSpanDelegatesToTracer(): void
    {
        $guard = new TelemetryGuard();

        $span = $guard->startSpan(new NoopTracer(), 'cluster.handshake');

        self::assertInstanceOf(NoopSpan::class, $span);
    }

    #[Test]
    public function spanHelpersSwallowThrowables(): void
    {
        $guard = new TelemetryGuard();
        $span = new NoopSpan();

        $guard->attribute($span, 'nexus.cluster.peer', 'node-1');
        $guard->recordException($span, new RuntimeException('x'));
        $guard->end($span);

        $this->addToAssertionCount(1);
    }
}
