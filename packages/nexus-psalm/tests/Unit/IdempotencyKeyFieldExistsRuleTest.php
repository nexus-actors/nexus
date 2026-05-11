<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class IdempotencyKeyFieldExistsRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsMissingOrNonStringIdempotencyKeyFields(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/IdempotencyKeyFieldExistsFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('IdempotencyKeyFieldMissing', $report);
        self::assertStringContainsString('IdempKeyBadMissingCommand', $report);
        self::assertStringContainsString('IdempKeyBadTypeCommand', $report);
        self::assertStringContainsString('missingField', $report);
        self::assertStringContainsString('orderId', $report);
        self::assertStringNotContainsString('IdempKeyGoodCommand', $report);
    }
}
