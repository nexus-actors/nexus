<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Formatter;

use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineFormatter::class)]
final class LineFormatterTest extends TestCase
{
    #[Test]
    public function format_emits_bracketed_iso_timestamp_channel_level_message(): void
    {
        $r = new Record(Level::Info, 'hello', [], 'app', 1_700_000_000.123);

        $line = (new LineFormatter())->format($r);

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\] app\.INFO: hello$/',
            $line,
        );
    }

    #[Test]
    public function format_appends_json_context_when_present(): void
    {
        $r = new Record(Level::Warning, 'oops', ['code' => 42], 'http', 1_700_000_000.000);

        $line = (new LineFormatter())->format($r);

        self::assertStringEndsWith('http.WARNING: oops {"code":42}', $line);
    }

    #[Test]
    public function format_omits_context_block_when_empty(): void
    {
        $r = new Record(Level::Debug, 'plain', [], 'app', 1_700_000_000.000);

        $line = (new LineFormatter())->format($r);

        self::assertStringEndsWith('app.DEBUG: plain', $line);
    }
}
