<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership\Message;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLivenessObserved;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeerLivenessObserved::class)]
final class PeerLivenessObservedTest extends TestCase
{
    #[Test]
    public function itCarriesTheIngressObservationTimestamp(): void
    {
        $peer = new NodeAddress('production', 'eu', 'payments', 'node-1');
        $endpoint = NodeEndpoint::fromString('10.0.0.1:7355');
        $observedAt = new DateTimeImmutable('2026-07-10 00:00:00.000000');

        $message = new PeerLivenessObserved($peer, $endpoint, $observedAt);

        self::assertSame($peer, $message->peer);
        self::assertSame($endpoint, $message->endpoint);
        self::assertSame($observedAt, $message->observedAt);
    }
}
