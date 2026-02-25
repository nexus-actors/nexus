<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit\Async;

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Tests\Support\TestFutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Future::class)]
final class FutureTest extends TestCase
{
    #[Test]
    public function await_returns_resolved_value(): void
    {
        $slot = new TestFutureSlot();
        $slot->resolve((object) ['ok' => true]);

        $future = new Future($slot);

        self::assertTrue($future->await()->ok);
    }
}
