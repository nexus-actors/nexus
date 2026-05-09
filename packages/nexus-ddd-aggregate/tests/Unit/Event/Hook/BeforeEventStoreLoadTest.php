<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreLoad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BeforeEventStoreLoad::class)]
final class BeforeEventStoreLoadTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new BeforeEventStoreLoad($streamId, 5, 100);

        self::assertSame($streamId, $event->streamId);
        self::assertSame(5, $event->fromSequenceNr);
        self::assertSame(100, $event->toSequenceNr);
    }
}
