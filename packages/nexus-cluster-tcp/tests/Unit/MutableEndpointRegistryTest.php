<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\MutableEndpointRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
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
        $endpoint = new NodeEndpoint('10.0.0.1', 7355);

        $registry->register($address, $endpoint);

        self::assertSame($endpoint, $registry->resolve($address));
    }

    #[Test]
    public function registerOverwritesPreviousEndpoint(): void
    {
        $registry = new MutableEndpointRegistry();
        $address = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $first = new NodeEndpoint('10.0.0.1', 7355);
        $second = new NodeEndpoint('10.0.0.2', 7356);

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
        $endpoint = new NodeEndpoint('10.0.0.1', 7355);

        $registry->register($address, $endpoint);

        self::assertSame($endpoint, $registry->resolve($sameAddress));
    }

    #[Test]
    public function registryTracksMultipleAddressesIndependently(): void
    {
        $registry = new MutableEndpointRegistry();
        $address1 = new NodeAddress('prod', 'eu', 'payments', 'node-1');
        $address2 = new NodeAddress('prod', 'eu', 'payments', 'node-2');
        $endpoint1 = new NodeEndpoint('10.0.0.1', 7355);
        $endpoint2 = new NodeEndpoint('10.0.0.2', 7355);

        $registry->register($address1, $endpoint1);
        $registry->register($address2, $endpoint2);

        self::assertSame($endpoint1, $registry->resolve($address1));
        self::assertSame($endpoint2, $registry->resolve($address2));
    }
}
