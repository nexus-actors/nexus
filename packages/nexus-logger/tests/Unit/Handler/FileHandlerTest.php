<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Handler;

use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\FileHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileHandler::class)]
final class FileHandlerTest extends TestCase
{
    private string $tmp = '';

    #[Test]
    public function handle_appends_each_record_as_a_line(): void
    {
        $h = new FileHandler($this->tmp, new LineFormatter());

        $h->handle(new Record(Level::Info, 'first', [], 'app', 1_700_000_000.000));
        $h->handle(new Record(Level::Warning, 'second', [], 'app', 1_700_000_000.500));

        $contents = (string) file_get_contents($this->tmp);
        $lines = explode("\n", rtrim($contents, "\n"));

        self::assertCount(2, $lines);
        self::assertStringContainsString('app.INFO: first', $lines[0]);
        self::assertStringContainsString('app.WARNING: second', $lines[1]);
    }

    protected function setUp(): void
    {
        $candidate = tempnam(sys_get_temp_dir(), 'nexus-logger-');
        self::assertNotFalse($candidate);
        $this->tmp = $candidate;
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }
}
