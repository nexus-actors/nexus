<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Monolog;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Monolog\MonologFormatterAdapter;
use Monadial\Nexus\Logger\Record;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonologFormatterAdapter::class)]
final class MonologFormatterAdapterTest extends TestCase
{
    #[Test]
    public function delegates_to_monolog_json_formatter(): void
    {
        $adapter = new MonologFormatterAdapter(new JsonFormatter());

        $line = $adapter->format(new Record(Level::Error, 'boom', ['code' => 1], 'app', 1_700_000_000.0));

        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('boom', $decoded['message']);
        self::assertSame('app', $decoded['channel']);
        self::assertSame(['code' => 1], $decoded['context']);
        self::assertSame('ERROR', $decoded['level_name']);
    }

    #[Test]
    public function delegates_to_monolog_line_formatter(): void
    {
        $monolog = new LineFormatter("%channel%.%level_name%: %message%");
        $adapter = new MonologFormatterAdapter($monolog);

        $line = $adapter->format(new Record(Level::Info, 'hello', [], 'app', 1_700_000_000.0));

        self::assertSame('app.INFO: hello', $line);
    }
}
