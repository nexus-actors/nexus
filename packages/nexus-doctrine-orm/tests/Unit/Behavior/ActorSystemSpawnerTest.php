<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSystemSpawner::class)]
final class ActorSystemSpawnerTest extends TestCase
{
    #[Test]
    public function delegatesToSystem(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $spawner = new ActorSystemSpawner($system);

        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $ref = $spawner->spawn($props, 'my-actor');

        self::assertSame('/user/my-actor', (string) $ref->path());
    }
}
