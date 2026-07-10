<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Membership\HandshakeAuthenticator;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandshakeAuthenticator::class)]
final class HandshakeAuthenticatorTest extends TestCase
{
    private HandshakeAuthenticator $auth;

    private TestClock $clock;

    /** The fixed Unix second the injected clock reports; sign() stamps issuedAt from it. */
    private int $now;

    #[Test]
    public function signsThenVerifiesItsOwnHandshake(): void
    {
        $signed = $this->auth->sign($this->handshake());

        self::assertNotNull($signed->nonce);
        self::assertNotNull($signed->issuedAt);
        self::assertNotNull($signed->mac);
        self::assertTrue($this->auth->verify($signed, $this->now));
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
        self::assertFalse($this->auth->verify($this->handshake(), $this->now));
    }

    #[Test]
    public function rejectsAHandshakeSignedWithADifferentSecret(): void
    {
        $signed = new HandshakeAuthenticator('the-wrong-secret')->sign($this->handshake());

        self::assertFalse($this->auth->verify($signed, $this->now));
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

        self::assertFalse(
            $this->auth->verify($tampered, $this->now),
            'a swapped node identity must invalidate the MAC',
        );
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
            $this->auth->verify($tampered, $this->now),
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
        // Each verify uses a freshly-signed handshake (fresh nonce) so the freshness window — not
        // the replay guard — is what is under test.
        $late = $this->auth->sign($this->handshake());
        self::assertNotNull($late->issuedAt);
        self::assertTrue($this->auth->verify($late, $late->issuedAt + 59));

        $early = $this->auth->sign($this->handshake());
        self::assertNotNull($early->issuedAt);
        self::assertTrue($this->auth->verify($early, $early->issuedAt - 59));
    }

    #[Test]
    public function acceptsExactlyAtTheFreshnessWindowEdge(): void
    {
        // Exactly `window` seconds away is still fresh (boundary is inclusive); one past is stale.
        // A fresh signature per assertion isolates the boundary from the replay guard.
        $edgeLate = $this->auth->sign($this->handshake());
        self::assertNotNull($edgeLate->issuedAt);
        self::assertTrue($this->auth->verify($edgeLate, $edgeLate->issuedAt + 60), 'window edge is inclusive');

        $edgeEarly = $this->auth->sign($this->handshake());
        self::assertNotNull($edgeEarly->issuedAt);
        self::assertTrue($this->auth->verify($edgeEarly, $edgeEarly->issuedAt - 60), 'window edge is symmetric');

        $stale = $this->auth->sign($this->handshake());
        self::assertNotNull($stale->issuedAt);
        self::assertFalse($this->auth->verify($stale, $stale->issuedAt + 61));
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
            issuedAt: $this->now,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($partial, $this->now));
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

        self::assertFalse($this->auth->verify($partial, $this->now));
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
            issuedAt: $this->now,
            mac: null,
        );

        self::assertFalse($this->auth->verify($partial, $this->now));
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
                $this->auth->verify($tampered, $this->now),
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

        self::assertFalse($this->auth->verify($tamperedCluster, $this->now), 'clusterName must be MAC-bound');

        $tamperedProtocol = new Handshake(
            clusterName: $signed->clusterName,
            node: $signed->node,
            advertise: $signed->advertise,
            protocolVersion: $signed->protocolVersion + 1,
            nonce: $signed->nonce,
            issuedAt: $signed->issuedAt,
            mac: $signed->mac,
        );

        self::assertFalse($this->auth->verify($tamperedProtocol, $this->now), 'protocolVersion must be MAC-bound');
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

    #[Test]
    public function signStampsIssuedAtFromTheInjectedClockNotWallClock(): void
    {
        // B3: sign() must read the injected clock, never time(); a fixed clock yields a fixed issuedAt.
        $signed = $this->auth->sign($this->handshake());

        self::assertSame($this->now, $signed->issuedAt);

        // Advancing the clock moves the next signature's issuedAt deterministically.
        $this->clock->advance(Duration::seconds(30));
        $later = $this->auth->sign($this->handshake());

        self::assertSame($this->now + 30, $later->issuedAt);
    }

    #[Test]
    public function rejectsAReplayOfAnAlreadyAcceptedHandshakeWithinTheWindow(): void
    {
        // I2: a captured handshake, re-presented verbatim while still fresh, must be rejected the
        // second time — the nonce is remembered on first acceptance.
        $signed = $this->auth->sign($this->handshake());

        self::assertTrue($this->auth->verify($signed, $this->now), 'first presentation is accepted');
        self::assertFalse(
            $this->auth->verify($signed, $this->now),
            'an identical replay within the freshness window must be rejected',
        );
        self::assertFalse(
            $this->auth->verify($signed, $this->now + 30),
            'the nonce stays remembered for the whole freshness window',
        );
    }

    #[Test]
    public function acceptsAgainOnceTheRememberedNonceHasAgedOutOfTheWindow(): void
    {
        // I2 eviction: once the original handshake is too stale to pass the freshness check its nonce
        // is evicted, so memory is bounded. (It can never be accepted again anyway — it's stale.)
        $signed = $this->auth->sign($this->handshake());
        self::assertNotNull($signed->nonce);

        self::assertTrue($this->auth->verify($signed, $this->now));

        // Verify a DIFFERENT, fresh handshake far in the future; this drives eviction of the stale nonce.
        $this->clock->advance(Duration::seconds(200));
        $fresh = $this->auth->sign($this->handshake());
        self::assertNotSame($signed->nonce, $fresh->nonce);
        self::assertTrue(
            $this->auth->verify($fresh, $this->now + 200),
            'a distinct fresh handshake is accepted, and evicts the aged-out nonce',
        );
    }

    #[Test]
    public function rememberedNoncesAreScopedToTheVerifierInstance(): void
    {
        // Two independent verifiers (same secret) each keep their own seen-nonce set — the replay guard
        // is a per-node defense, matching the thread-confinement ownership model.
        $signed = $this->auth->sign($this->handshake());
        $other = new HandshakeAuthenticator('cluster-secret', clock: $this->clock);

        self::assertTrue($this->auth->verify($signed, $this->now));
        self::assertTrue(
            $other->verify($signed, $this->now),
            'a second verifier that never saw the nonce still accepts it',
        );
    }

    protected function setUp(): void
    {
        // Deterministic clock: sign() stamps issuedAt from it, so tests never touch wall-clock time.
        $this->clock = new TestClock(new DateTimeImmutable('@1700000000'));
        $this->now = 1700000000;
        $this->auth = new HandshakeAuthenticator('cluster-secret', clock: $this->clock);
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
