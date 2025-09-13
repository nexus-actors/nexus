<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorState::class)]
final class ActorStateTest extends TestCase
{
    #[Test]
    public function allStatesExist(): void
    {
        self::assertSame('new', ActorState::New->value);
        self::assertSame('starting', ActorState::Starting->value);
        self::assertSame('running', ActorState::Running->value);
        self::assertSame('suspended', ActorState::Suspended->value);
        self::assertSame('stopping', ActorState::Stopping->value);
        self::assertSame('stopped', ActorState::Stopped->value);
    }

    #[Test]
    public function validTransitions(): void
    {
        self::assertTrue(ActorState::New->canTransitionTo(ActorState::Starting));
        self::assertTrue(ActorState::Starting->canTransitionTo(ActorState::Running));
        self::assertTrue(ActorState::Running->canTransitionTo(ActorState::Suspended));
        self::assertTrue(ActorState::Running->canTransitionTo(ActorState::Stopping));
        self::assertTrue(ActorState::Suspended->canTransitionTo(ActorState::Running));
        self::assertTrue(ActorState::Suspended->canTransitionTo(ActorState::Stopping));
        self::assertTrue(ActorState::Stopping->canTransitionTo(ActorState::Stopped));
    }

    #[Test]
    public function invalidTransitions(): void
    {
        self::assertFalse(ActorState::Stopped->canTransitionTo(ActorState::Running));
        self::assertFalse(ActorState::Stopped->canTransitionTo(ActorState::Starting));
        self::assertFalse(ActorState::New->canTransitionTo(ActorState::Running));
        self::assertFalse(ActorState::New->canTransitionTo(ActorState::Stopped));
        self::assertFalse(ActorState::Running->canTransitionTo(ActorState::New));
        self::assertFalse(ActorState::Running->canTransitionTo(ActorState::Starting));
    }
}
