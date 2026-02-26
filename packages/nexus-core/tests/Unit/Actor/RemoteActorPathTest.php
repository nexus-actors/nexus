<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorPath;
use Monadial\Nexus\Core\Actor\RemoteActorPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RemoteActorPathTest extends TestCase
{
    #[Test]
    public function remotePathContainsAddressAndLocalPath(): void
    {
        $path = RemoteActorPath::fromAddress(
            'nexus://payments/us-east-1/order-svc-a',
            ActorPath::fromString('/user/orders'),
        );

        self::assertSame('nexus://payments/us-east-1/order-svc-a/user/orders', (string) $path);
        self::assertSame('/user/orders', (string) $path->localPath());
    }

    #[Test]
    public function localPathWrapperDelegatesToActorPath(): void
    {
        $local = LocalActorPath::fromString('/user/cart');

        self::assertSame('cart', $local->name());
        self::assertSame(2, $local->depth());
        self::assertSame('/user/cart', (string) $local);
        self::assertTrue($local->equals(ActorPath::fromString('/user/cart')));
    }
}
