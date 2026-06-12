<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelActorNameResolver::class)]
final class ChannelActorNameResolverTest extends TestCase
{
    #[Test]
    public function resolves_to_deterministic_url_safe_name(): void
    {
        $a = ChannelActorNameResolver::resolve('lobby');
        $b = ChannelActorNameResolver::resolve('lobby');

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^ws-channel-[a-z0-9]+$/', $a);
    }

    #[Test]
    public function different_keys_produce_different_names(): void
    {
        self::assertNotSame(
            ChannelActorNameResolver::resolve('lobby'),
            ChannelActorNameResolver::resolve('room42'),
        );
    }

    #[Test]
    public function handles_keys_with_unsafe_characters(): void
    {
        $name = ChannelActorNameResolver::resolve('café/42 + 9');

        self::assertMatchesRegularExpression('/^ws-channel-[a-z0-9]+$/', $name);
    }
}
