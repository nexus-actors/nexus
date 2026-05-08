<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function exec;

final class OneCommandHandlerRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsDuplicateCommandHandlersForSameCommand(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json packages/nexus-psalm/tests/Fixture/OneCommandHandlerFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('DuplicateCommandHandler', $report);
        self::assertStringContainsString('DupCommandX', $report);
        self::assertStringNotContainsString('DupCommandY', $report);
    }
}
