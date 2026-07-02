<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Tests\Unit;

use Monadial\Nexus\Logger\Mdc;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Mdc::class)]
final class MdcTest extends TestCase
{
    #[Test]
    public function put_then_get_round_trips_a_value(): void
    {
        Mdc::put('host', 'thread-0');
        self::assertSame('thread-0', Mdc::get('host'));
    }

    #[Test]
    public function get_all_returns_every_static_entry(): void
    {
        Mdc::put('host', 'thread-0');
        Mdc::put('pid', 1234);

        self::assertSame(['host' => 'thread-0', 'pid' => 1234], Mdc::getAll());
    }

    #[Test]
    public function remove_drops_a_static_key(): void
    {
        Mdc::put('host', 'thread-0');
        Mdc::put('pid', 1234);
        Mdc::remove('host');

        self::assertSame(['pid' => 1234], Mdc::getAll());
        self::assertNull(Mdc::get('host'));
    }

    #[Test]
    public function clear_static_empties_the_map(): void
    {
        Mdc::put('host', 'thread-0');
        Mdc::clearStatic();

        self::assertSame([], Mdc::getAll());
    }

    #[Test]
    public function scope_temporarily_overrides_and_restores_previous(): void
    {
        Mdc::put('requestId', 'outer');

        $result = Mdc::scope(['requestId' => 'inner', 'userId' => 42], static function () {
            self::assertSame('inner', Mdc::get('requestId'));
            self::assertSame(42, Mdc::get('userId'));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame('outer', Mdc::get('requestId'));
        self::assertNull(Mdc::get('userId'));
    }

    #[Test]
    public function scope_restores_previous_even_on_exception(): void
    {
        Mdc::put('requestId', 'outer');

        try {
            Mdc::scope(['requestId' => 'inner'], static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame('outer', Mdc::get('requestId'));
    }

    protected function tearDown(): void
    {
        Mdc::clearStatic();
    }
}
