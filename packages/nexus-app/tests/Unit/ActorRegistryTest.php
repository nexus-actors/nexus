<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\ActorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorRegistry::class)]
final class ActorRegistryTest extends TestCase
{
    #[Test]
    public function registersAndReturnsNameToClassMap(): void
    {
        $registry = new ActorRegistry();
        $registry->register('greeter', 'App\\Actor\\GreeterActor');

        self::assertSame(['greeter' => 'App\\Actor\\GreeterActor'], $registry->all());
    }
}
