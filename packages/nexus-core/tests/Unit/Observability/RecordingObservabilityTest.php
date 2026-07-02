<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Observability;

use Monadial\Nexus\Core\Tests\Support\Observability\RecordingObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RecordingObservabilityTest extends TestCase
{
    #[Test]
    public function recordsSpansMetricsAndReportsCurrentContext(): void
    {
        $observability = new RecordingObservability();

        $span = $observability->tracer()->startSpan('op', SpanKind::Consumer, ['k' => 'v']);
        self::assertTrue($observability->currentContext()->spanContext->isValid());

        $observability->meter()->counter('c')->add(2, ['t' => 'x']);
        $span->end();

        self::assertCount(1, $observability->spans());
        self::assertSame('op', $observability->spans()[0]->name);
        self::assertCount(1, $observability->metrics());
        self::assertSame(2, $observability->metrics()[0]->value);
        self::assertFalse($observability->currentContext()->spanContext->isValid());
    }
}
