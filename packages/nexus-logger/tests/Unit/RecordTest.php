<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(Record::class)]
final class RecordTest extends TestCase
{
    #[Test]
    public function create_without_placeholders_passes_message_through_unchanged(): void
    {
        $r = Record::create(Level::Info, 'plain message', ['extra' => 1], 'app');

        self::assertSame('plain message', $r->message);
        self::assertSame(['extra' => 1], $r->context);
    }

    #[Test]
    public function create_substitutes_placeholders_and_strips_used_keys(): void
    {
        $r = Record::create(Level::Info, 'user {name} did {action}', [
            'action' => 'login',
            'extra' => 'kept',
            'name' => 'tomas',
        ], 'auth');

        self::assertSame('user tomas did login', $r->message);
        self::assertSame(['extra' => 'kept'], $r->context);
    }

    #[Test]
    public function create_leaves_unmatched_placeholders_literal(): void
    {
        $r = Record::create(Level::Info, 'missing {x}', [], 'app');

        self::assertSame('missing {x}', $r->message);
    }

    #[Test]
    public function create_renders_stringable_placeholders(): void
    {
        $obj = new class implements Stringable {
            public function __toString(): string
            {
                return 'STRINGED';
            }
        };

        $r = Record::create(Level::Info, 'val={v}', ['v' => $obj], 'app');

        self::assertSame('val=STRINGED', $r->message);
        self::assertSame([], $r->context);
    }

    #[Test]
    public function create_leaves_non_scalar_non_stringable_placeholders_literal(): void
    {
        $r = Record::create(Level::Info, 'val={v}', ['v' => ['nested' => 1]], 'app');

        self::assertSame('val={v}', $r->message);
        self::assertSame(['v' => ['nested' => 1]], $r->context);
    }

    #[Test]
    public function timestamp_is_populated_at_construction(): void
    {
        $before = microtime(true);
        $r = Record::create(Level::Info, 'x', [], 'app');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $r->timestamp);
        self::assertLessThanOrEqual($after, $r->timestamp);
    }
}
