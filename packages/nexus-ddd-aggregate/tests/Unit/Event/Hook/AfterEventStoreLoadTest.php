<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreLoad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AfterEventStoreLoad::class)]
final class AfterEventStoreLoadTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new AfterEventStoreLoad($streamId, 1, 9, []);

        self::assertSame($streamId, $event->streamId);
        self::assertSame(1, $event->fromSequenceNr);
        self::assertSame(9, $event->toSequenceNr);
        self::assertSame([], $event->events);
    }
}
