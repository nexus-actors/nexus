<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Metrics;

use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricOutcome::class)]
final class MetricOutcomeTest extends TestCase
{
    #[Test]
    public function casesAreLockedToCanonicalStringValues(): void
    {
        self::assertSame('started', MetricOutcome::Started->value);
        self::assertSame('succeeded', MetricOutcome::Succeeded->value);
        self::assertSame('validation_failed', MetricOutcome::ValidationFailed->value);
        self::assertSame('access_denied', MetricOutcome::AccessDenied->value);
        self::assertSame('idempotent_short_circuit', MetricOutcome::IdempotentShortCircuit->value);
        self::assertSame('occ_retry_exhausted', MetricOutcome::OccRetryExhausted->value);
        self::assertSame('terminal_failure', MetricOutcome::TerminalFailure->value);
    }

    #[Test]
    public function exposesExactlySevenCases(): void
    {
        self::assertCount(7, MetricOutcome::cases());
    }
}
