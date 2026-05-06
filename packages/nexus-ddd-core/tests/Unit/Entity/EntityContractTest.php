<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Entity;

use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifiable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EntityContractTest extends TestCase
{
    #[Test]
    public function entityExtendsIdentifiable(): void
    {
        $reflection = new ReflectionClass(Entity::class);
        self::assertTrue($reflection->isInterface());
        self::assertContains(Identifiable::class, $reflection->getInterfaceNames());
        self::assertTrue($reflection->hasMethod('equals'));
    }

    #[Test]
    public function eventSourceableExtendsIdentifiable(): void
    {
        $reflection = new ReflectionClass(EventSourceable::class);
        self::assertTrue($reflection->isInterface());
        self::assertContains(Identifiable::class, $reflection->getInterfaceNames());
        foreach (['pullRecordedEvents', 'replay', 'version', 'stateVersion'] as $m) {
            self::assertTrue($reflection->hasMethod($m), "EventSourceable must declare $m()");
        }
    }
}
