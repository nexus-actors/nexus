<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Handler;

use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleHandler::class)]
final class ConsoleHandlerTest extends TestCase
{
    #[Test]
    public function handle_writes_formatted_line_with_trailing_newline_to_stream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        $handler = new ConsoleHandler($stream, new LineFormatter());

        $handler->handle(new Record(Level::Info, 'hi', [], 'app', 1_700_000_000.000));

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringEndsWith("\n", (string) $output);
        self::assertStringContainsString('app.INFO: hi', (string) $output);
    }
}
