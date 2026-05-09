<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot\Hook;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotLoad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AfterSnapshotLoad::class)]
final class AfterSnapshotLoadTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = Option::none();
        $event = new AfterSnapshotLoad($streamId, $snapshot);

        self::assertSame($streamId, $event->streamId);
        self::assertSame($snapshot, $event->snapshot);
    }
}
