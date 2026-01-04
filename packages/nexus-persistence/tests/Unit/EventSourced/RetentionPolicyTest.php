<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetentionPolicy::class)]
final class RetentionPolicyTest extends TestCase
{
    #[Test]
    public function noneKeepsEverything(): void
    {
        $policy = RetentionPolicy::none();

        self::assertSame(PHP_INT_MAX, $policy->keepSnapshots);
        self::assertFalse($policy->deleteEventsToSnapshot);
    }

    #[Test]
    public function snapshotAndEventsStoresConfigValues(): void
    {
        $policy = RetentionPolicy::snapshotAndEvents(keepSnapshots: 3, deleteEventsTo: true);

        self::assertSame(3, $policy->keepSnapshots);
        self::assertTrue($policy->deleteEventsToSnapshot);
    }

    #[Test]
    public function snapshotAndEventsDefaultsDeleteToFalse(): void
    {
        $policy = RetentionPolicy::snapshotAndEvents(keepSnapshots: 5);

        self::assertSame(5, $policy->keepSnapshots);
        self::assertFalse($policy->deleteEventsToSnapshot);
    }
}
