<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\Future;
use Monadial\Nexus\Core\Actor\FutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(Future::class)]
final class FutureTest extends TestCase
{
    #[Test]
    public function await_returns_resolved_value(): void
    {
        $value = new stdClass();
        $value->name = 'test';

        $slot = $this->createPreResolvedSlot($value);
        $future = new Future($slot);

        self::assertSame($value, $future->await());
    }

    #[Test]
    public function await_throws_on_failed_slot(): void
    {
        $slot = $this->createFailedSlot(new RuntimeException('boom'));
        $future = new Future($slot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $future->await();
    }

    #[Test]
    public function is_resolved_delegates_to_slot(): void
    {
        $slot = $this->createPreResolvedSlot(new stdClass());
        $future = new Future($slot);

        self::assertTrue($future->isResolved());
    }

    #[Test]
    public function map_transforms_result(): void
    {
        $value = new stdClass();
        $value->count = 5;

        $slot = $this->createPreResolvedSlot($value);
        $future = new Future($slot);

        $mapped = $future->map(static function (object $v): object {
            $result = new stdClass();
            $result->doubled = $v->count * 2;

            return $result;
        });

        $result = $mapped->await();
        self::assertSame(10, $result->doubled);
    }

    #[Test]
    public function flat_map_chains_futures(): void
    {
        $first = new stdClass();
        $first->id = 1;

        $second = new stdClass();
        $second->name = 'chained';

        $slot1 = $this->createPreResolvedSlot($first);
        $slot2 = $this->createPreResolvedSlot($second);

        $future = new Future($slot1);
        $chained = $future->flatMap(static fn(object $v): Future => new Future($slot2));

        self::assertSame($second, $chained->await());
    }

    private function createPreResolvedSlot(object $value): FutureSlot
    {
        $slot = $this->createStub(FutureSlot::class);
        $slot->method('await')->willReturn($value);
        $slot->method('isResolved')->willReturn(true);

        return $slot;
    }

    private function createFailedSlot(Throwable $e): FutureSlot
    {
        $slot = $this->createStub(FutureSlot::class);
        $slot->method('await')->willThrowException($e);
        $slot->method('isResolved')->willReturn(true);

        return $slot;
    }
}
