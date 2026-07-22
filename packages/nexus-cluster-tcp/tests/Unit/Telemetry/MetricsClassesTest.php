<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Telemetry;

use Monadial\Nexus\Cluster\Tcp\Telemetry\AskMetrics;
use Monadial\Nexus\Cluster\Tcp\Telemetry\ConnectionMetrics;
use Monadial\Nexus\Cluster\Tcp\Telemetry\MembershipMetrics;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function ksort;

#[CoversClass(AskMetrics::class)]
#[CoversClass(ConnectionMetrics::class)]
#[CoversClass(MembershipMetrics::class)]
final class MetricsClassesTest extends TestCase
{
    #[Test]
    public function connectionMetricsCreatesEveryDocumentedInstrumentEagerly(): void
    {
        $meter = new RecordingMeter();
        new ConnectionMetrics($meter);

        self::assertSame(
            [
                'nexus.cluster.frames.sent',
                'nexus.cluster.frames.received',
                'nexus.cluster.frames.buffered',
                'nexus.cluster.frames.dropped',
                'nexus.cluster.frames.decode_failed',
                'nexus.cluster.frames.handler_failed',
                'nexus.cluster.control_send.failed',
                'nexus.cluster.handshake.rejected',
                'nexus.cluster.socket_write.failed',
                'nexus.cluster.messages.sent',
                'nexus.cluster.messages.received',
                'nexus.cluster.messages.local_shortcircuit',
                'nexus.cluster.messages.unroutable',
                'nexus.cluster.send_buffer.dropped',
            ],
            array_keys($meter->counters),
        );
        self::assertSame(
            [
                'nexus.cluster.bytes.sent',
                'nexus.cluster.bytes.received',
            ],
            array_keys($meter->histograms),
        );
    }

    #[Test]
    public function connectionMetricsPinsInstrumentUnits(): void
    {
        $meter = new RecordingMeter();
        new ConnectionMetrics($meter);

        $expectedCounterUnits = [
            'nexus.cluster.control_send.failed' => '{send}',
            'nexus.cluster.frames.buffered' => '{frame}',
            'nexus.cluster.frames.decode_failed' => '{frame}',
            'nexus.cluster.frames.dropped' => '{frame}',
            'nexus.cluster.frames.handler_failed' => '{frame}',
            'nexus.cluster.frames.received' => '{frame}',
            'nexus.cluster.frames.sent' => '{frame}',
            'nexus.cluster.handshake.rejected' => '{handshake}',
            'nexus.cluster.messages.local_shortcircuit' => '{message}',
            'nexus.cluster.messages.received' => '{message}',
            'nexus.cluster.messages.sent' => '{message}',
            'nexus.cluster.messages.unroutable' => '{message}',
            'nexus.cluster.send_buffer.dropped' => '{message}',
            'nexus.cluster.socket_write.failed' => '{write}',
        ];
        $expectedHistogramUnits = [
            'nexus.cluster.bytes.received' => 'By',
            'nexus.cluster.bytes.sent' => 'By',
        ];

        $actualCounterUnits = $meter->counterUnits;
        ksort($actualCounterUnits);
        $actualHistogramUnits = $meter->histogramUnits;
        ksort($actualHistogramUnits);

        self::assertSame($expectedCounterUnits, $actualCounterUnits);
        self::assertSame($expectedHistogramUnits, $actualHistogramUnits);
    }

    #[Test]
    public function membershipMetricsCreatesEveryDocumentedInstrumentEagerly(): void
    {
        $meter = new RecordingMeter();
        new MembershipMetrics($meter);

        self::assertSame(
            [
                'nexus.cluster.nodes.suspected',
                'nexus.cluster.nodes.recovered',
                'nexus.cluster.nodes.pruned',
                'nexus.cluster.heartbeats.received',
                'nexus.cluster.gossip.rounds',
            ],
            array_keys($meter->counters),
        );
    }

    #[Test]
    public function membershipMetricsPinsInstrumentUnits(): void
    {
        $meter = new RecordingMeter();
        new MembershipMetrics($meter);

        $expectedCounterUnits = [
            'nexus.cluster.gossip.rounds' => '{round}',
            'nexus.cluster.heartbeats.received' => '{heartbeat}',
            'nexus.cluster.nodes.pruned' => '{node}',
            'nexus.cluster.nodes.recovered' => '{node}',
            'nexus.cluster.nodes.suspected' => '{node}',
        ];

        $actualCounterUnits = $meter->counterUnits;
        ksort($actualCounterUnits);

        self::assertSame($expectedCounterUnits, $actualCounterUnits);
    }

    #[Test]
    public function askMetricsCreatesInstrumentsAndWiresThePendingGauge(): void
    {
        $meter = new RecordingMeter();
        new AskMetrics($meter, static fn(): int => 7);

        self::assertSame(
            [
                'nexus.cluster.asks.sent',
                'nexus.cluster.asks.resolved',
                'nexus.cluster.asks.timed_out',
                'nexus.cluster.asks.capacity_rejected',
            ],
            array_keys($meter->counters),
        );
        self::assertSame(['nexus.cluster.ask.duration'], array_keys($meter->histograms));
        self::assertSame(7, $meter->observableGaugeValue('nexus.cluster.asks.pending'));
    }

    #[Test]
    public function askMetricsPinsInstrumentUnits(): void
    {
        $meter = new RecordingMeter();
        new AskMetrics($meter, static fn(): int => 0);

        $expectedCounterUnits = [
            'nexus.cluster.asks.capacity_rejected' => '{message}',
            'nexus.cluster.asks.resolved' => '{message}',
            'nexus.cluster.asks.sent' => '{message}',
            'nexus.cluster.asks.timed_out' => '{message}',
        ];

        $actualCounterUnits = $meter->counterUnits;
        ksort($actualCounterUnits);

        self::assertSame($expectedCounterUnits, $actualCounterUnits);
        self::assertSame(['nexus.cluster.ask.duration' => 'ms'], $meter->histogramUnits);
        self::assertSame(['nexus.cluster.asks.pending' => '{message}'], $meter->gaugeUnits);
    }

    #[Test]
    public function instrumentPropertiesAreExposedForRecording(): void
    {
        $metrics = new ConnectionMetrics(new RecordingMeter());

        $metrics->framesSent->add(1, ['frame.type' => 'message']);
        $metrics->bytesSent->record(128);

        $this->addToAssertionCount(1);
    }
}
