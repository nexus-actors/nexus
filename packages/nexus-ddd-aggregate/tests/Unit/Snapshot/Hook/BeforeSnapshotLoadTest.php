<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotLoad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BeforeSnapshotLoad::class)]
final class BeforeSnapshotLoadTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new BeforeSnapshotLoad($streamId);

        self::assertSame($streamId, $event->streamId);
    }
}
