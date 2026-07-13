<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Export;

use Monadial\Nexus\Observability\Otel\Export\ActorForwardingLogRecordExporter;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingMetricExporter;
use Monadial\Nexus\Observability\Otel\Export\ActorForwardingSpanExporter;
use Monadial\Nexus\Observability\Otel\Export\ExportLogs;
use Monadial\Nexus\Observability\Otel\Export\ExportMetrics;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingLogExporter;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingMeter;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingRef;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingSpanExporter;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorForwardingSpanExporter::class)]
#[CoversClass(ActorForwardingMetricExporter::class)]
#[CoversClass(ActorForwardingLogRecordExporter::class)]
final class ActorForwardingExportersTest extends TestCase
{
    #[Test]
    public function bufferingModeAccumulatesAndAttachDrainsInOrder(): void
    {
        $inner = new RecordingSpanExporter();
        $exporter = new ActorForwardingSpanExporter($inner);

        $exporter->export(['span-1'])->await();
        $exporter->export(['span-2'])->await();
        $exporter->export(['span-3'])->await();

        $ref = new RecordingRef();
        $exporter->attach($ref);

        self::assertCount(3, $ref->offered);
        self::assertSame(['span-1'], $ref->offered[0]->batch);
        self::assertSame(['span-2'], $ref->offered[1]->batch);
        self::assertSame(['span-3'], $ref->offered[2]->batch);

        $exporter->export(['span-4'])->await();

        self::assertCount(4, $ref->offered);
        self::assertSame(['span-4'], $ref->offered[3]->batch);
        self::assertSame([], $inner->exported);
    }

    #[Test]
    public function bufferOverflowDropsOldestAndCounts(): void
    {
        $inner = new RecordingSpanExporter();
        $meter = new RecordingMeter();
        $exporter = new ActorForwardingSpanExporter($inner, $meter);

        for ($i = 0; $i < 65; $i++) {
            $exporter->export(["span-$i"])->await();
        }

        $ref = new RecordingRef();
        $exporter->attach($ref);

        self::assertCount(64, $ref->offered);
        self::assertSame(['span-1'], $ref->offered[0]->batch);
        self::assertSame(['span-64'], $ref->offered[63]->batch);
        self::assertSame(1.0, $meter->counterSum('nexus.observability.export.dropped'));

        $adds = $meter->counters['nexus.observability.export.dropped']->adds;
        self::assertSame(['reason' => 'buffer_full', 'signal' => 'spans'], $adds[0]['attributes']);
    }

    #[Test]
    public function liveModeCountsMailboxDrops(): void
    {
        $inner = new RecordingSpanExporter();
        $meter = new RecordingMeter();
        $exporter = new ActorForwardingSpanExporter($inner, $meter);

        $ref = new RecordingRef();
        $ref->offerResult = EnqueueResult::Dropped;
        $exporter->attach($ref);

        $future = $exporter->export(['span-x']);

        self::assertTrue($future->await());
        self::assertCount(1, $ref->offered);
        self::assertSame(['span-x'], $ref->offered[0]->batch);
        self::assertSame(1.0, $meter->counterSum('nexus.observability.export.dropped'));

        $adds = $meter->counters['nexus.observability.export.dropped']->adds;
        self::assertSame(['reason' => 'mailbox_full', 'signal' => 'spans'], $adds[0]['attributes']);
    }

    #[Test]
    public function deadRefFallsBackToDirectDelegation(): void
    {
        $inner = new RecordingSpanExporter();
        $exporter = new ActorForwardingSpanExporter($inner);

        $ref = new RecordingRef();
        $ref->alive = false;
        $exporter->attach($ref);

        $future = $exporter->export(['span-direct']);

        self::assertTrue($future->await());
        self::assertSame([['span-direct']], $inner->exported);
        self::assertSame([], $ref->told);
        self::assertSame([], $ref->offered);

        // Subsequent exports keep going direct, permanently.
        $exporter->export(['span-direct-2'])->await();
        self::assertSame([['span-direct'], ['span-direct-2']], $inner->exported);
    }

    #[Test]
    public function attachToDeadRefFlushesBufferDirectlyWithoutGoingLive(): void
    {
        $inner = new RecordingSpanExporter();
        $exporter = new ActorForwardingSpanExporter($inner);

        $exporter->export(['span-1'])->await();
        $exporter->export(['span-2'])->await();

        $ref = new RecordingRef();
        $ref->alive = false;
        $exporter->attach($ref);

        self::assertSame([['span-1'], ['span-2']], $inner->exported);
        self::assertSame([], $ref->told);
        self::assertSame([], $ref->offered);

        $exporter->export(['span-3'])->await();

        self::assertSame([['span-1'], ['span-2'], ['span-3']], $inner->exported);
        self::assertSame([], $ref->told);
        self::assertSame([], $ref->offered);
    }

    #[Test]
    public function metricTemporalityDelegatesToInner(): void
    {
        $inner = new class implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface {
            #[Override]
            public function temporality(MetricMetadataInterface $metric): Temporality|string|null
            {
                return Temporality::DELTA;
            }

            #[Override]
            public function export(iterable $batch): bool
            {
                return true;
            }

            #[Override]
            public function shutdown(): bool
            {
                return true;
            }

            #[Override]
            public function forceFlush(): bool
            {
                return true;
            }
        };
        $metadata = $this->createStub(MetricMetadataInterface::class);
        $exporter = new ActorForwardingMetricExporter($inner);

        $result = $exporter->temporality($metadata);

        self::assertSame(Temporality::DELTA, $result);
    }

    #[Test]
    public function shutdownWhileBufferingFlushesBufferThroughInner(): void
    {
        $inner = new RecordingSpanExporter();
        $exporter = new ActorForwardingSpanExporter($inner);

        $exporter->export(['span-1'])->await();
        $exporter->export(['span-2'])->await();

        $result = $exporter->shutdown();

        self::assertTrue($result);
        self::assertSame([['span-1'], ['span-2']], $inner->exported);
    }

    #[Test]
    public function logExporterBuffersAndDrainsThroughAttach(): void
    {
        $inner = new RecordingLogExporter();
        $exporter = new ActorForwardingLogRecordExporter($inner);

        $exporter->export(['log-1'])->await();

        $ref = new RecordingRef();
        $exporter->attach($ref);

        self::assertCount(1, $ref->offered);
        self::assertInstanceOf(ExportLogs::class, $ref->offered[0]);
        self::assertSame(['log-1'], $ref->offered[0]->batch);
        self::assertSame([], $inner->exported);
    }

    #[Test]
    public function metricExporterForwardsWrappedBatchInLiveMode(): void
    {
        $meter = new RecordingMeter();
        $exporter = new ActorForwardingMetricExporter(new class implements PushMetricExporterInterface {
            #[Override]
            public function export(iterable $batch): bool
            {
                return true;
            }

            #[Override]
            public function shutdown(): bool
            {
                return true;
            }

            #[Override]
            public function forceFlush(): bool
            {
                return true;
            }
        }, $meter);

        $ref = new RecordingRef();
        $exporter->attach($ref);

        $result = $exporter->export(['metric-1']);

        self::assertTrue($result);
        self::assertCount(1, $ref->offered);
        self::assertInstanceOf(ExportMetrics::class, $ref->offered[0]);
        self::assertSame(['metric-1'], $ref->offered[0]->batch);
    }
}
