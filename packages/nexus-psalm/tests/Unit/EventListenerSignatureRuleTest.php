<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function exec;

final class EventListenerSignatureRuleTest extends TestCase
{
    #[Test]
    public function psalmReportsInvalidEventListenerSignatures(): void
    {
        exec(
            'cd ' . escapeshellarg(dirname(__DIR__, 4))
            . ' && vendor/bin/psalm --no-progress --output-format=json packages/nexus-psalm/tests/Fixture/EventListenerSignatureFixture.php 2>/dev/null',
            $output,
            $exitCode,
        );

        $report = implode("\n", $output);
        self::assertStringContainsString('InvalidEventListenerSignature', $report);
        self::assertStringContainsString('BadEventListenerNoInvoke', $report);
        self::assertStringContainsString('BadEventListenerWrongReturn', $report);
        self::assertStringNotContainsString('GoodEventListener"', $report);
        self::assertStringNotContainsString('GoodEventListenerWithContext', $report);
    }
}
