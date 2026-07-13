<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Export;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Observability\Otel\Export\ExportSpans;
use Monadial\Nexus\Observability\Otel\Export\FlushNow;
use Monadial\Nexus\Observability\Otel\Export\OtlpExportActor;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingLogExporter;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingMeter;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingMetricExporter;
use Monadial\Nexus\Observability\Otel\Tests\Support\RecordingSpanExporter;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtlpExportActor::class)]
final class OtlpExportActorTest extends TestCase
{
    private StepRuntime $runtime;

    private ActorSystem $system;

    private RecordingSpanExporter $spans;

    private RecordingMetricExporter $metrics;

    private RecordingLogExporter $logs;

    private RecordingMeter $meter;

    private int $actorSeq = 0;

    #[Test]
    public function spanBatchesReachTheInnerExporter(): void
    {
        $ref = $this->spawnActor();
        $ref->tell(new ExportSpans(['span-a', 'span-b']));
        $this->runtime->drain();

        self::assertSame([['span-a', 'span-b']], $this->spans->exported);
    }

    #[Test]
    public function aThrowingInnerExporterDropsOnlyThatBatchAndCounts(): void
    {
        $this->spans->throwOnExport = true;
        $ref = $this->spawnActor();
        $ref->tell(new ExportSpans(['bad']));
        $this->runtime->drain();

        $this->spans->throwOnExport = false;
        $ref->tell(new ExportSpans(['good']));
        $this->runtime->drain();

        self::assertSame([['good']], $this->spans->exported);
        self::assertSame(1.0, $this->meter->counterSum('nexus.observability.export.dropped'));
    }

    #[Test]
    public function flushNowForceFlushesAllInnerExporters(): void
    {
        $ref = $this->spawnActor();
        $ref->tell(new FlushNow());
        $this->runtime->drain();

        self::assertSame(1, $this->spans->forceFlushes);
        self::assertSame(1, $this->metrics->forceFlushes);
        self::assertSame(1, $this->logs->forceFlushes);
    }

    #[Test]
    public function postStopForceFlushesAllInnerExporters(): void
    {
        $ref = $this->spawnActor();
        $this->system->stop($ref);
        $this->runtime->drain();

        self::assertSame(1, $this->spans->forceFlushes);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('otlp-export-test', $this->runtime, clock: $this->runtime->clock());
        $this->spans = new RecordingSpanExporter();
        $this->metrics = new RecordingMetricExporter();
        $this->logs = new RecordingLogExporter();
        $this->meter = new RecordingMeter();
    }

    /**
     * @return ActorRef<object>
     */
    private function spawnActor(): ActorRef
    {
        $actor = new OtlpExportActor($this->spans, $this->metrics, $this->logs, $this->meter);

        $ref = $this->system->spawn($actor->props(), 'otlp-export-' . $this->actorSeq++);
        $this->runtime->drain();

        return $ref;
    }
}
