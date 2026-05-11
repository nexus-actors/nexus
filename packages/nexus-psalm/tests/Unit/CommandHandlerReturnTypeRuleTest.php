<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class CommandHandlerReturnTypeRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsNonVoidReturnsOnHandlerMethods(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/CommandHandlerReturnTypeFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('CommandHandlerNonVoidReturn', $report);
        self::assertStringContainsString('BadReturnTypeService', $report);
        self::assertStringContainsString('place', $report);
        self::assertStringContainsString('cancel', $report);
        self::assertStringContainsString('archive', $report);
        self::assertStringNotContainsString('GoodReturnTypeService', $report);
    }
}
