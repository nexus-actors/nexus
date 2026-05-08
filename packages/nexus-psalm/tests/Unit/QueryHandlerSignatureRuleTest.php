<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function exec;

final class QueryHandlerSignatureRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsInvalidQueryHandlerSignatures(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json packages/nexus-psalm/tests/Fixture/QueryHandlerSignatureFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('InvalidQueryHandlerSignature', $report);
        self::assertStringContainsString('BadQueryHandlerNoInvoke', $report);
        self::assertStringContainsString('BadQueryHandlerVoidReturn', $report);
        self::assertStringNotContainsString('GoodQueryHandler"', $report);
    }
}
