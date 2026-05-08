<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function exec;

final class MessagingMessageBodyRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsNonReadonlyMessageBodyForConcreteImplementers(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json packages/nexus-psalm/tests/Fixture/MessagingMessageBodyFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('NonReadonlyMessageBody', $report);
        self::assertStringContainsString('BadMutableCommand', $report);
        self::assertStringContainsString('BadNonFinalCommand', $report);
        self::assertStringContainsString('BadMutableQuery', $report);
        self::assertStringNotContainsString('GoodCommand', $report);
        self::assertStringNotContainsString('GoodQuery', $report);
    }
}
