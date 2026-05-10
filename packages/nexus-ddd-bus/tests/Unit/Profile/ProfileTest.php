<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Profile;

use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profile::class)]
final class ProfileTest extends TestCase
{
    #[Test]
    public function casesAreSyncAsyncActor(): void
    {
        self::assertSame('sync', Profile::Sync->value);
        self::assertSame('async', Profile::Async->value);
        self::assertSame('actor', Profile::Actor->value);
    }

    #[Test]
    public function isSyncReturnsTrueForSyncOnly(): void
    {
        self::assertTrue(Profile::Sync->isSync());
        self::assertFalse(Profile::Async->isSync());
        self::assertFalse(Profile::Actor->isSync());
    }

    #[Test]
    public function allowsAsyncBusReturnsTrueForAsyncAndActor(): void
    {
        self::assertFalse(Profile::Sync->allowsAsyncBus());
        self::assertTrue(Profile::Async->allowsAsyncBus());
        self::assertTrue(Profile::Actor->allowsAsyncBus());
    }

    #[Test]
    public function allowsActorBusReturnsTrueForActorOnly(): void
    {
        self::assertFalse(Profile::Sync->allowsActorBus());
        self::assertFalse(Profile::Async->allowsActorBus());
        self::assertTrue(Profile::Actor->allowsActorBus());
    }
}
