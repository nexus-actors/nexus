<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\ChannelCapacityExceededException;
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

    // ========================================================================
    // Cardinality cap bounds channel actors (SEC-002)
    // ========================================================================

    #[Test]
    public function churning_unique_keys_is_refused_past_the_cap(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system, maxChannels: 3);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        for ($i = 0; $i < 3; $i++) {
            $reg->resolveOrSpawn("ws-channel-{$i}", $props);
        }

        self::assertSame(3, $reg->count());

        $this->expectException(ChannelCapacityExceededException::class);
        $reg->resolveOrSpawn('ws-channel-overflow', $props);
    }

    #[Test]
    public function reconnecting_an_existing_channel_past_the_cap_still_works(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system, maxChannels: 2);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $a = $reg->resolveOrSpawn('ws-channel-a', $props);
        $reg->resolveOrSpawn('ws-channel-b', $props);

        // At the cap, but an EXISTING channel is served from cache, not spawned.
        self::assertSame($a, $reg->resolveOrSpawn('ws-channel-a', $props));
    }

    #[Test]
    public function removing_a_channel_frees_a_slot_for_a_new_one(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $reg = new ChannelActorRegistry($system, maxChannels: 1);
        $props = Props::fromBehavior(Behavior::receive(static fn() => Behavior::same()));

        $first = $reg->resolveOrSpawn('ws-channel-a', $props);
        $reg->remove('ws-channel-a'); // e.g. evicted on last-close

        // The freed slot lets a new key spawn without tripping the cap.
        $second = $reg->resolveOrSpawn('ws-channel-b', $props);

        self::assertNotSame($first, $second);
        self::assertSame(1, $reg->count());
    }
}
