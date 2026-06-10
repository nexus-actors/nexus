<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit\Async;

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureResult;
use Monadial\Nexus\Runtime\Exception\FutureException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(Future::class)]
final class FutureCombinatorsTest extends TestCase
{
    #[Test]
    public function resolved_returns_completed_future(): void
    {
        $value = new stdClass();

        $future = Future::resolved($value);

        self::assertTrue($future->isResolved());
        self::assertSame($value, $future->await());
    }

    #[Test]
    public function failed_throws_on_await(): void
    {
        $error = new TestFutureException('boom');

        $future = Future::failed($error);

        self::assertTrue($future->isResolved());
        $this->expectException(FutureException::class);
        $this->expectExceptionMessage('boom');
        $future->await();
    }

    #[Test]
    public function all_collects_resolved_values_by_key(): void
    {
        $a = new stdClass();
        $a->name = 'a';
        $b = new stdClass();
        $b->name = 'b';

        $combined = Future::all([
            'first' => Future::resolved($a),
            'second' => Future::resolved($b),
        ]);

        $result = $combined->await();

        self::assertInstanceOf(FutureResult::class, $result);
        self::assertSame(['first' => $a, 'second' => $b], $result->values);
    }

    #[Test]
    public function all_propagates_first_failure(): void
    {
        $error = new TestFutureException('first failed');

        $combined = Future::all([
            Future::failed($error),
            Future::resolved(new stdClass()),
        ]);

        $this->expectException(FutureException::class);
        $this->expectExceptionMessage('first failed');
        $combined->await();
    }

    #[Test]
    public function all_returns_empty_result_for_empty_input(): void
    {
        $combined = Future::all([]);

        $result = $combined->await();

        self::assertInstanceOf(FutureResult::class, $result);
        self::assertSame([], $result->values);
    }
}

final class TestFutureException extends RuntimeException implements FutureException {}
