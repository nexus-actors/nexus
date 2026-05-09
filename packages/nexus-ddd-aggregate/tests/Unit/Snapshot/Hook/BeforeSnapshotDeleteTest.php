<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotDelete;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BeforeSnapshotDelete::class)]
final class BeforeSnapshotDeleteTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new BeforeSnapshotDelete($streamId, 5);

        self::assertSame($streamId, $event->streamId);
        self::assertSame(5, $event->upToSequenceNr);
    }
}
