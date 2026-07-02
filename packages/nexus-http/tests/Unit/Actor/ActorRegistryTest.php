<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;
use Monadial\Nexus\Http\Exception\DuplicateActorNameException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorRegistry::class)]
final class ActorRegistryTest extends TestCase
{
    #[Test]
    public function freeze_captures_the_latest_mutation_state(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $registry->register('store', $props, ActorMode::WorkerLocal)->poolSingleton();

        $entries = $registry->freeze();

        self::assertCount(1, $entries);
        self::assertSame(ActorMode::PoolSingleton, $entries[0]->mode);
    }

    #[Test]
    public function register_returns_registration_with_default_mode(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $registration = $registry->register('store', $props, ActorMode::WorkerLocal);

        self::assertSame('store', $registration->name());
        self::assertSame(ActorMode::WorkerLocal, $registration->currentMode());
    }

    #[Test]
    public function register_throws_on_duplicate_name(): void
    {
        $registry = new ActorRegistry();
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $registry->register('store', $props, ActorMode::WorkerLocal);
        $this->expectException(DuplicateActorNameException::class);
        $registry->register('store', $props, ActorMode::WorkerLocal);
    }
}
