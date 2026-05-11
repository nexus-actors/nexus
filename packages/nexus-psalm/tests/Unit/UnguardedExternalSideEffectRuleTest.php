<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class UnguardedExternalSideEffectRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsExternalCallsFromHandlersWithoutIdempotencyKey(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/UnguardedExternalSideEffectFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('UnguardedExternalSideEffect', $report);
        self::assertStringContainsString('BadUnguardedHandler', $report);
        self::assertStringContainsString('BadUnguardedMultiService', $report);
        self::assertStringNotContainsString('GoodGuardedHandler', $report);
    }
}
