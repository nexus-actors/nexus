<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Stream;

use Monadial\Nexus\Ddd\Aggregate\Event\Stream\StreamName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamName::class)]
final class StreamNameTest extends TestCase
{
    #[Test]
    public function constructsWithStringValue(): void
    {
        $name = new StreamName('ddd_events');
        self::assertSame('ddd_events', $name->value);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        self::assertTrue((new StreamName('ddd_events'))->equals(new StreamName('ddd_events')));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        self::assertFalse((new StreamName('ddd_events'))->equals(new StreamName('ddd_events_orders')));
    }
}
