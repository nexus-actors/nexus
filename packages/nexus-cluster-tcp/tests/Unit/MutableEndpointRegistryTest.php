<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\MutableEndpointRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MutableEndpointRegistry::class)]
final class MutableEndpointRegistryTest extends TestCase
{
    #[Test]
    public function resolveReturnsNullBeforeRegistration(): void
    {
        $registry = new MutableEndpointRegistry();
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');

        self::assertNull($registry->resolve($address));
    }

    #[Test]
    public function registerThenResolveReturnsEndpoint(): void
    {
        $registry = new MutableEndpointRegistry();
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $endpoint = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));

        $registry->register($address, $endpoint);

        self::assertSame($endpoint, $registry->resolve($address));
    }

    #[Test]
    public function registerOverwritesPreviousEndpoint(): void
    {
        $registry = new MutableEndpointRegistry();
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $first = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $second = new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7356));

        $registry->register($address, $first);
        $registry->register($address, $second);

        self::assertSame($second, $registry->resolve($address));
    }

    #[Test]
    public function registerUsesToPathPrefixAsKey(): void
    {
        $registry = new MutableEndpointRegistry();
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $sameAddress = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $endpoint = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));

        $registry->register($address, $endpoint);

        self::assertSame($endpoint, $registry->resolve($sameAddress));
    }

    #[Test]
    public function registryTracksMultipleAddressesIndependently(): void
    {
        $registry = new MutableEndpointRegistry();
        $address1 = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $address2 = new NodeAddress('prod', 'eu', 'payments', 'node-2');
        $endpoint1 = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $endpoint2 = new NodeEndpoint(Host::of('10.0.0.2'), Port::of(7355));

        $registry->register($address1, $endpoint1);
        $registry->register($address2, $endpoint2);

        self::assertSame($endpoint1, $registry->resolve($address1));
        self::assertSame($endpoint2, $registry->resolve($address2));
    }

    #[Test]
    public function registryEvictsOldestWhenCapacityExceeded(): void
    {
        // Endpoints are learned from unauthenticated gossip; the map must stay bounded so a peer
        // cannot exhaust memory by gossiping endless fabricated addresses.
        $registry = new MutableEndpointRegistry(maxEntries: 2);

        $address1 = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $address2 = new NodeAddress('prod', 'eu', 'payments', 'node-2');
        $address3 = new NodeAddress('prod', 'eu', 'payments', 'node-3');
        $endpoint = new NodeEndpoint(Host::of('10.0.0.9'), Port::of(7355));

        $registry->register($address1, $endpoint);
        $registry->register($address2, $endpoint);
        $registry->register($address3, $endpoint); // evicts the oldest (address1)

        self::assertNull($registry->resolve($address1), 'oldest entry must be evicted at capacity');
        self::assertSame($endpoint, $registry->resolve($address2));
        self::assertSame($endpoint, $registry->resolve($address3));
    }

    #[Test]
    public function reRegisteringExistingAddressDoesNotEvict(): void
    {
        $registry = new MutableEndpointRegistry(maxEntries: 2);

        $address1 = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $address2 = new NodeAddress('prod', 'eu', 'payments', 'node-2');
        $first = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7355));
        $refreshed = new NodeEndpoint(Host::of('10.0.0.1'), Port::of(7356));

        $registry->register($address1, $first);
        $registry->register($address2, $first);
        $registry->register($address1, $refreshed); // refresh existing — must not evict address2

        self::assertSame($refreshed, $registry->resolve($address1));
        self::assertSame($first, $registry->resolve($address2));
    }
}
