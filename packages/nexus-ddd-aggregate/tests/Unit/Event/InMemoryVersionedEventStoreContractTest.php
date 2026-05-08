<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event;

use Monadial\Nexus\Ddd\Aggregate\Event\InMemoryVersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Event\VersionedEventStore;
use Monadial\Nexus\Ddd\Aggregate\Tests\Support\VersionedEventStoreContractTest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryVersionedEventStore::class)]
final class InMemoryVersionedEventStoreContractTest extends VersionedEventStoreContractTest
{
    #[Override]
    protected function createStore(): VersionedEventStore
    {
        return new InMemoryVersionedEventStore();
    }
}
