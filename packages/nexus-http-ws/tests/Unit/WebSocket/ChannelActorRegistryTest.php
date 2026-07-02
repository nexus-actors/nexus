<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelActorRegistry::class)]
final class ChannelActorRegistryTest extends TestCase
{
    #[Test]
    public function resolve_or_spawn_returns_same_ref_for_same_name(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $a = $reg->resolveOrSpawn('ws-channel-x', $props);
        $b = $reg->resolveOrSpawn('ws-channel-x', $props);

        self::assertSame($a, $b);
    }

    #[Test]
    public function resolve_or_spawn_returns_different_refs_for_different_names(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $a = $reg->resolveOrSpawn('ws-channel-a', $props);
        $b = $reg->resolveOrSpawn('ws-channel-b', $props);

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function remove_clears_cache_entry(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $first = $reg->resolveOrSpawn('ws-channel-x', $props);
        $reg->remove('ws-channel-x');
        $again = $reg->resolveOrSpawn('ws-channel-y', $props);

        self::assertNotSame($first, $again);
    }
}
