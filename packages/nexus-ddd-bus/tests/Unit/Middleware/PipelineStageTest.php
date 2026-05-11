<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PipelineStage::class)]
final class PipelineStageTest extends TestCase
{
    #[Test]
    public function casesAreFourteenCanonicalStages(): void
    {
        $cases = PipelineStage::cases();

        self::assertCount(14, $cases);
    }

    #[Test]
    public function casesHaveExpectedValuesInOrder(): void
    {
        $expected = [
            'causation',
            'otel-span',
            'logging-start',
            'metrics-start',
            'validation',
            'authorization',
            'idempotency-reserve',
            'occ-retry',
            'handler',
            'idempotency-commit',
            'event-drain',
            'metrics-end',
            'logging-end',
            'span-close',
        ];

        $actual = array_map(static fn(PipelineStage $s): string => $s->value, PipelineStage::cases());

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function namedCasesResolveToStringValues(): void
    {
        self::assertSame('causation', PipelineStage::Causation->value);
        self::assertSame('otel-span', PipelineStage::OtelSpan->value);
        self::assertSame('logging-start', PipelineStage::LoggingStart->value);
        self::assertSame('metrics-start', PipelineStage::MetricsStart->value);
        self::assertSame('validation', PipelineStage::Validation->value);
        self::assertSame('authorization', PipelineStage::Authorization->value);
        self::assertSame('idempotency-reserve', PipelineStage::IdempotencyReserve->value);
        self::assertSame('occ-retry', PipelineStage::OccRetry->value);
        self::assertSame('handler', PipelineStage::Handler->value);
        self::assertSame('idempotency-commit', PipelineStage::IdempotencyCommit->value);
        self::assertSame('event-drain', PipelineStage::EventDrain->value);
        self::assertSame('metrics-end', PipelineStage::MetricsEnd->value);
        self::assertSame('logging-end', PipelineStage::LoggingEnd->value);
        self::assertSame('span-close', PipelineStage::SpanClose->value);
    }

    #[Test]
    public function namesReturnsFourteenStringValues(): void
    {
        $names = PipelineStage::names();

        self::assertCount(14, $names);

        foreach ($names as $name) {
            self::assertIsString($name);
        }

        self::assertSame('causation', $names[0]);
        self::assertSame('span-close', $names[13]);
    }

    #[Test]
    public function namesIsList(): void
    {
        self::assertTrue(array_is_list(PipelineStage::names()));
    }
}
