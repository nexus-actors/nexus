<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Handler;

use Monadial\Nexus\Ddd\Messaging\Handler\EventListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class EventListenerTest extends TestCase
{
    #[Test]
    public function eventListenerIsAMarkerInterface(): void
    {
        $reflection = new ReflectionClass(EventListener::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
