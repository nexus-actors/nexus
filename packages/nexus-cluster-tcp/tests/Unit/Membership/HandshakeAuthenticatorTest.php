<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function time;

#[CoversClass(HandshakeAuthenticator::class)]
final class HandshakeAuthenticatorTest extends TestCase
{
    private HandshakeAuthenticator $auth;

    #[Test]
    public function signsThenVerifiesItsOwnHandshake(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->nonce);
        self::assertNotNull($signed->issuedAt);
        self::assertNotNull($signed->mac);
        self::assertTrue($this->auth->verify($signed, time()));
    }

    #[Test]
    public function preservesTheIdentityClaimWhenSigning(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertSame('production', $signed->clusterName);
        self::assertSame('10.0.0.1:7355', $signed->advertise);
        self::assertSame(1, $signed->protocolVersion);
        self::assertSame('node-1', $signed->node['node']);
    }

    #[Test]
    public function rejectsAnUnsignedHandshake(): void
    {
        self::assertFalse($this->auth->verify($this->handshake(), time()));
    }

    #[Test]
    public function rejectsAHandshakeSignedWithADifferentSecret(): void
    {
        $signed = new HandshakeAuthenticator('the-wrong-secret')->sign($this->handshake());

        self::assertFalse($this->auth->verify($signed, time()));
    }

    #[Test]
    public function rejectsTamperedIdentityFields(): void
    {
        $signed = $this->auth->sign($this->handshake());

        $tampered = new Handshake(
            clusterName: $signed->clusterName,
            node: ['application' => 'payments', 'cluster' => 'production', 'datacenter' => 'eu', 'node' => 'node-EVIL'],
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion,
            nonce: $signed->nonce,
            issuedAt: $signed->issuedAt,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($tampered, time()), 'a swapped node identity must invalidate the MAC');
    }

    #[Test]
    public function rejectsTamperedAdvertiseEndpoint(): void
    {
        $signed = $this->auth->sign($this->handshake());

        $tampered = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: '10.6.6.6:7355',
            protocolVersion: $signed->protocolVersion,
            nonce: $signed->nonce,
            issuedAt: $signed->issuedAt,
            mac: $signed->mac,
        );

        self::assertFalse(
            $this->auth->verify($tampered, time()),
            'a redirected advertise endpoint must invalidate the MAC',
        );
    }

    #[Test]
    public function rejectsAStaleHandshakeOutsideTheFreshnessWindow(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->issuedAt);
        // Default window is 60 s; 61 s later is stale.
        self::assertFalse($this->auth->verify($signed, $signed->issuedAt + 61));
    }

    #[Test]
    public function rejectsAFutureDatedHandshakeOutsideTheWindow(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->issuedAt);
        self::assertFalse($this->auth->verify($signed, $signed->issuedAt - 61), 'clock-skew guard is symmetric');
    }

    #[Test]
    public function acceptsWithinTheFreshnessWindow(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->issuedAt);
        self::assertTrue($this->auth->verify($signed, $signed->issuedAt + 59));
        self::assertTrue($this->auth->verify($signed, $signed->issuedAt - 59));
    }

    #[Test]
    public function acceptsExactlyAtTheFreshnessWindowEdge(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->issuedAt);
        // Exactly `window` seconds away is still fresh (boundary is inclusive); one past is stale.
        self::assertTrue($this->auth->verify($signed, $signed->issuedAt + 60), 'window edge is inclusive');
        self::assertTrue($this->auth->verify($signed, $signed->issuedAt - 60), 'window edge is symmetric');
        self::assertFalse($this->auth->verify($signed, $signed->issuedAt + 61));
    }

    #[Test]
    public function rejectsAHandshakeMissingOnlyTheNonce(): void
    {
        $signed = $this->auth->sign($this->handshake());

        // A partial signature — every field present except the nonce — must not authenticate,
        // even with a fresh timestamp and a real MAC.
        $partial = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion,
            nonce: null,
            issuedAt: time(),
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($partial, time()));
    }

    #[Test]
    public function rejectsAHandshakeMissingOnlyTheIssuedAt(): void
    {
        $signed = $this->auth->sign($this->handshake());

        $partial = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion,
            nonce: $signed->nonce,
            issuedAt: null,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($partial, time()));
    }

    #[Test]
    public function rejectsAHandshakeMissingOnlyTheMac(): void
    {
        $signed = $this->auth->sign($this->handshake());

        $partial = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion,
            nonce: $signed->nonce,
            issuedAt: time(),
            mac: null,
        );

        self::assertFalse($this->auth->verify($partial, time()));
    }

    #[Test]
    public function macBindsEveryNodeIdentitySubfield(): void
    {
        $signed = $this->auth->sign($this->handshake());

        foreach (['application', 'cluster', 'datacenter', 'node'] as $field) {
            $tamperedNode = $signed->node;
            $tamperedNode[$field] = 'tampered-' . $field;

            $tampered = new Handshake(
                clusterName: $signed->clusterName,
                node: $tamperedNode,
                advertise: $signed->advertise,
                protocolVersion: $signed->protocolVersion,
                nonce: $signed->nonce,
                issuedAt: $signed->issuedAt,
                mac: $signed->mac,
            );

            self::assertFalse(
                $this->auth->verify($tampered, time()),
                "node.{$field} must be bound by the MAC",
            );
        }
    }

    #[Test]
    public function macBindsClusterNameAndProtocolVersion(): void
    {
        $signed = $this->auth->sign($this->handshake());

        $tamperedCluster = new Handshake(
            clusterName: 'a-different-cluster',
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion,
            nonce: $signed->nonce,
            issuedAt: $signed->issuedAt,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($tamperedCluster, time()), 'clusterName must be MAC-bound');

        $tamperedProtocol = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion + 1,
            nonce: $signed->nonce,
            issuedAt: $signed->issuedAt,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($tamperedProtocol, time()), 'protocolVersion must be MAC-bound');
    }

    #[Test]
    public function honoursACustomFreshnessWindow(): void
    {
        $auth = new HandshakeAuthenticator('cluster-secret', Duration::seconds(5));
        $signed = $auth->sign($this->handshake());

        self::assertNotNull($signed->issuedAt);
        self::assertTrue($auth->verify($signed, $signed->issuedAt + 4));
        self::assertFalse($auth->verify($signed, $signed->issuedAt + 6));
    }

    protected function setUp(): void
    {
        $this->auth = new HandshakeAuthenticator('cluster-secret');
    }

    private function handshake(): Handshake
    {
        return new Handshake(
            clusterName: 'production',
            node: ['application' => 'payments', 'cluster' => 'production', 'datacenter' => 'eu', 'node' => 'node-1'],
            advertise: '10.0.0.1:7355',
        );
    }
}
