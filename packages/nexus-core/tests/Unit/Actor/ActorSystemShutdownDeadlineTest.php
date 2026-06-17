<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSystem::class)]
final class ActorSystemShutdownDeadlineTest extends TestCase
{
    private TestRuntime $runtime;

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $system = ActorSystem::create('test', $this->runtime);

        $system->shutdown(Duration::millis(10));
        $system->shutdown(Duration::millis(10));

        self::assertTrue(true);
    }

    #[Test]
    public function shutdownDelegatesToRuntime(): void
    {
        $system = ActorSystem::create('test', $this->runtime);
        $system->run();

        self::assertTrue($system->isRunning());

        $system->shutdown(Duration::millis(10));

        self::assertFalse($system->isRunning());
    }

    #[Test]
    public function unresponsiveActorIsForceStoppedAtDeadline(): void
    {
        $system = ActorSystem::create('test', $this->runtime);

        // Behavior::receive returning same() means the actor ignores PoisonPill
        // (PoisonPill arrives as a user message via tell() and is never processed
        // by the fiber under TestRuntime — the message loop callable is stored but
        // never invoked). shutdown() must force-stop via initiateStop() after the
        // deadline expires.
        $ref = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn($c, $m) => Behavior::same())),
            'ignorant',
        );

        self::assertTrue($ref->isAlive());

        $system->shutdown(Duration::millis(1));

        self::assertFalse($ref->isAlive());
    }

    #[Test]
    public function shutdownForceStopsAllChildren(): void
    {
        $system = ActorSystem::create('test', $this->runtime);

        $ref1 = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn($c, $m) => Behavior::same())),
            'child-1',
        );
        $ref2 = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn($c, $m) => Behavior::same())),
            'child-2',
        );

        $system->shutdown(Duration::millis(1));

        self::assertFalse($ref1->isAlive());
        self::assertFalse($ref2->isAlive());
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
    }
}
