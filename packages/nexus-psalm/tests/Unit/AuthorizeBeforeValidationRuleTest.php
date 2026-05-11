<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

final class AuthorizeBeforeValidationRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsUnknownStagesInAuthorizeAttribute(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json'
            . ' packages/nexus-psalm/tests/Fixture/AuthorizeBeforeValidationFixture.php 2>/dev/null',
            $output,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('AuthorizeBeforeStageUnknown', $report);
        self::assertStringContainsString('BadAuthorizeStageService', $report);
        self::assertStringContainsString('validashion', $report);
        self::assertStringContainsString('not-a-stage', $report);
        self::assertStringNotContainsString('GoodAuthorizeStageService', $report);
    }
}
