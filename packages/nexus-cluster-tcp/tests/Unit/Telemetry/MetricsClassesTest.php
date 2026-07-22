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
    public function instrumentPropertiesAreExposedForRecording(): void
    {
        $metrics = new ConnectionMetrics(new RecordingMeter());

        $metrics->framesSent->add(1, ['frame.type' => 'message']);
        $metrics->bytesSent->record(128);

        $this->addToAssertionCount(1);
    }
}
