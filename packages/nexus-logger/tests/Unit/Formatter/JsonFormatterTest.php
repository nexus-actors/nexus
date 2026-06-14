<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit\Formatter;

use Monadial\Nexus\Logger\Formatter\JsonFormatter;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
final class JsonFormatterTest extends TestCase
{
    #[Test]
    public function format_emits_a_valid_json_object_with_canonical_keys(): void
    {
        $r = new Record(Level::Error, 'boom', ['userId' => 7], 'app', 1_700_000_000.000);

        $line = (new JsonFormatter())->format($r);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['channel', 'context', 'extra', 'level', 'message', 'timestamp'], array_keys($decoded));
        self::assertSame('app', $decoded['channel']);
        self::assertSame(['userId' => 7], $decoded['context']);
        self::assertSame('error', $decoded['level']);
        self::assertSame('boom', $decoded['message']);
    }

    #[Test]
    public function format_does_not_escape_slashes_or_unicode(): void
    {
        $r = new Record(Level::Info, 'path /a/b', ['emoji' => 'café'], 'app', 0.0);

        $line = (new JsonFormatter())->format($r);

        self::assertStringContainsString('/a/b', $line);
        self::assertStringContainsString('café', $line);
    }
}
