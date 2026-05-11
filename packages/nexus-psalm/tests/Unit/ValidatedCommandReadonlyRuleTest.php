<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class ValidatedCommandReadonlyRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsValidatedHandlersOnNonReadonlyCommands(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/ValidatedCommandReadonlyFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('ValidatedCommandNotReadonly', $report);
        self::assertStringContainsString('ValidatedReadonlyBadCommand', $report);
        self::assertStringContainsString('BadValidatedHandlerService', $report);
        self::assertStringNotContainsString('GoodValidatedHandlerService', $report);
    }
}
