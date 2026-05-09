<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreAppend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BeforeEventStoreAppend::class)]
final class BeforeEventStoreAppendTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new BeforeEventStoreAppend($streamId, 3, []);

        self::assertSame($streamId, $event->streamId);
        self::assertSame(3, $event->expectedVersion);
        self::assertSame([], $event->events);
    }
}
