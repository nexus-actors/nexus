<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class CommandReturnValueIgnoredRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsAssignmentOfDispatchCommandReturn(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/CommandReturnValueIgnoredFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('CommandReturnValueAssigned', $report);
        // The issue points at the assignment statement; the snippet text
        // includes the `$result = $this->bus->dispatchCommand(...)` line.
        self::assertStringContainsString('$result = ', $report);
        self::assertStringContainsString('dispatchCommand', $report);
    }
}
