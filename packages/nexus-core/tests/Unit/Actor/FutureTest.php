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

    #[Test]
    public function zip_awaits_all_futures(): void
    {
        $a = new stdClass();
        $a->val = 'a';
        $b = new stdClass();
        $b->val = 'b';
        $c = new stdClass();
        $c->val = 'c';

        $fa = new Future($this->createPreResolvedSlot($a));
        $fb = new Future($this->createPreResolvedSlot($b));
        $fc = new Future($this->createPreResolvedSlot($c));

        $zipped = Future::zip($fa, $fb, $fc);
        $results = $zipped->await();

        self::assertIsArray($results);
        self::assertCount(3, $results);
        self::assertSame('a', $results[0]->val);
        self::assertSame('b', $results[1]->val);
        self::assertSame('c', $results[2]->val);
    }

    #[Test]
    public function zip_propagates_failure(): void
    {
        $a = new Future($this->createPreResolvedSlot(new stdClass()));
        $b = new Future($this->createFailedSlot(new RuntimeException('zip-fail')));

        $zipped = Future::zip($a, $b);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zip-fail');
        $zipped->await();
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
