<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function implode;

final class AggregateEmitsOnlyEventsRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsBusCallsFromInsideAggregates(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/AggregateEmitsOnlyEventsFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('AggregateEmitsOnlyEvents', $report);
        self::assertStringContainsString('BadEmitsAggregateUsesCommandBus', $report);
        self::assertStringContainsString('BadEmitsAggregateUsesEventBus', $report);
        self::assertStringContainsString('BadEmitsAggregateUsesQueryBus', $report);
        self::assertStringNotContainsString('GoodEmitsAggregate"', $report);
        self::assertStringNotContainsString('EmitsFixtureRegularService', $report);
    }
}
