<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSystem::class)]
final class ActorSystemLiveCountTest extends TestCase
{
    #[Test]
    public function countsLiveRootActors(): void
    {
        $system = ActorSystem::create('count-test', new TestRuntime());
        self::assertSame(0, $system->liveActorCount());

        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $system->spawn(Props::fromBehavior($behavior), 'a');
        $system->spawn(Props::fromBehavior($behavior), 'b');

        self::assertSame(2, $system->liveActorCount());
    }
}
