<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\MapEndpointResolver;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapEndpointResolver::class)]
final class MapEndpointResolverTest extends TestCase
{
    #[Test]
    public function resolveKnownAddressReturnsEndpoint(): void
    {
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $endpoint = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $resolver = new MapEndpointResolver([
            $address->toPathPrefix() => $endpoint,
        ]);

        self::assertSame($endpoint, $resolver->resolve($address));
    }

    #[Test]
    public function resolveUnknownAddressReturnsNull(): void
    {
        $resolver = new MapEndpointResolver([]);
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');

        self::assertNull($resolver->resolve($address));
    }

    #[Test]
    public function resolveUsesToPathPrefixAsKey(): void
    {
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $endpoint = new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7355));
        $sameAddress = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $resolver = new MapEndpointResolver([
            $sameAddress->toPathPrefix() => $endpoint,
        ]);

        self::assertSame($endpoint, $resolver->resolve($address));
    }

    #[Test]
    public function resolveDistinguishesDifferentAddresses(): void
    {
        $address1 = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $address2 = new NodeAddress('prod', 'eu', 'payments', 'node-2');
        $endpoint1 = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $endpoint2 = new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7355));
        $resolver = new MapEndpointResolver([
            $address1->toPathPrefix() => $endpoint1,
            $address2->toPathPrefix() => $endpoint2,
        ]);

        self::assertSame($endpoint1, $resolver->resolve($address1));
        self::assertSame($endpoint2, $resolver->resolve($address2));
    }
}
