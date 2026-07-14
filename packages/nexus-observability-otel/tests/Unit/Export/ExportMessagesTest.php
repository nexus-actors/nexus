<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Export;

use Monadial\Nexus\Core\Actor\UntracedMessage;
use Monadial\Nexus\Observability\Otel\Export\ExportLogs;
use Monadial\Nexus\Observability\Otel\Export\ExportMetrics;
use Monadial\Nexus\Observability\Otel\Export\ExportSpans;
use Monadial\Nexus\Observability\Otel\Export\FlushNow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportSpans::class)]
#[CoversClass(ExportMetrics::class)]
#[CoversClass(ExportLogs::class)]
#[CoversClass(FlushNow::class)]
final class ExportMessagesTest extends TestCase
{
    #[Test]
    public function allExportMessagesAreUntraced(): void
    {
        self::assertInstanceOf(UntracedMessage::class, new ExportSpans([]));
        self::assertInstanceOf(UntracedMessage::class, new ExportMetrics([]));
        self::assertInstanceOf(UntracedMessage::class, new ExportLogs([]));
        self::assertInstanceOf(UntracedMessage::class, new FlushNow());
    }

    #[Test]
    public function batchMessagesCarryTheirBatch(): void
    {
        $batch = ['a', 'b'];

        self::assertSame($batch, (new ExportSpans($batch))->batch);
        self::assertSame($batch, (new ExportMetrics($batch))->batch);
        self::assertSame($batch, (new ExportLogs($batch))->batch);
    }
}
