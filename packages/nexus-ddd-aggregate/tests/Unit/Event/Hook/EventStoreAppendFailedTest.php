<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\EventStoreAppendFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EventStoreAppendFailed::class)]
final class EventStoreAppendFailedTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $exception = new RuntimeException('boom');
        $event = new EventStoreAppendFailed($streamId, 4, [], $exception);

        self::assertSame($streamId, $event->streamId);
        self::assertSame(4, $event->expectedVersion);
        self::assertSame([], $event->events);
        self::assertSame($exception, $event->exception);
    }
}
