<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Clock\ClockInterface;

use function abs;
use function bin2hex;
use function hash_equals;
use function hash_hmac;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-api
 *
 * Authenticates cluster handshakes with a shared-secret HMAC.
 *
 * Without this, `clusterName` is only a label: any process that can reach the bind
 * port and speak the framing completes a handshake and joins. When a cluster secret
 * is configured, a joining node must prove it holds the secret by signing its
 * handshake; a peer that cannot produce a valid signature is rejected before any
 * ingress is wired, so it can neither join the membership view nor deliver messages.
 *
 * This is cluster-membership authentication (holding the secret ⇒ you are a member),
 * the same model as Serf/Consul gossip keys — not per-node identity binding, which
 * TLS client-certificate verification provides at the transport layer and is the
 * recommended complement. A compromised member leaks the shared secret; rotate it and
 * fence the network accordingly.
 *
 * Signature covers the full identity claim (cluster name, protocol version, node
 * address, advertise endpoint) plus a per-handshake nonce and issue timestamp. The
 * timestamp is checked against `freshnessWindow` (default 60 s) so a captured handshake
 * expires once outside that window; comparison is constant-time. Within the window a
 * bounded, time-evicted seen-nonce set rejects an exact replay of a handshake this
 * verifier has already accepted — so a captured frame cannot be replayed *to the same node*
 * while it is still fresh. The seen-nonce set is per-node, so this does NOT stop a captured
 * handshake from being replayed to a *different* node within the freshness window; cross-node
 * replay is mitigated only by TLS (`withTls(verifyPeer: true)`), which prevents on-path capture
 * in the first place. The nonce set is evicted lazily on every {@see verify()} call, dropping
 * entries whose issue timestamp has aged past the freshness window, which bounds memory
 * to at most one entry per distinct handshake seen within the last `freshnessWindow`.
 *
 * Thread-confinement: this object is owned by the recv path / membership actor of a
 * single node and is never shared across Swoole threads, so the mutable nonce set needs
 * no locking — matching the rest of the package's confinement convention.
 */
final class HandshakeAuthenticator
{
    private readonly int $freshnessWindowSeconds;

    private readonly ClockInterface $clock;

    /**
     * Nonces of handshakes already accepted, mapped to the Unix second they were issued.
     * Bounded by lazy eviction of entries older than the freshness window.
     *
     * @var array<string, int>
     */
    private array $seenNonces = [];

    public function __construct(
        private readonly string $secret,
        ?Duration $freshnessWindow = null,
        ?ClockInterface $clock = null,
    ) {
        $this->freshnessWindowSeconds = ($freshnessWindow ?? Duration::seconds(60))->toSeconds();
        $this->clock = $clock ?? new class implements ClockInterface {
            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    /**
     * Return a copy of `$handshake` carrying a fresh nonce, issue timestamp, and HMAC.
     */
    public function sign(Handshake $handshake): Handshake
    {
        $nonce = bin2hex(random_bytes(16));
        $issuedAt = $this->clock->now()->getTimestamp();

        return new Handshake(
            clusterName: $handshake->clusterName,
            node: $handshake->node,
            advertise: $handshake->advertise,
            protocolVersion: $handshake->protocolVersion,
            nonce: $nonce,
            issuedAt: $issuedAt,
            mac: $this->mac($handshake, $nonce, $issuedAt),
        );
    }

    /**
     * Whether `$handshake` carries a valid, fresh, non-replayed signature for this secret.
     * A handshake with no signature fields always fails when a secret is enforced. A
     * handshake whose nonce this verifier has already accepted within the freshness window
     * is rejected as a replay. On acceptance the nonce is remembered until it ages out.
     */
    public function verify(Handshake $handshake, int $nowUnix): bool
    {
        $nonce = $handshake->nonce;
        $issuedAt = $handshake->issuedAt;
        $mac = $handshake->mac;

        if ($nonce === null || $issuedAt === null || $mac === null) {
            return false;
        }

        if (abs($nowUnix - $issuedAt) > $this->freshnessWindowSeconds) {
            return false;
        }

        if (!hash_equals($this->mac($handshake, $nonce, $issuedAt), $mac)) {
            return false;
        }

        $this->evictStaleNonces($nowUnix);

        if (isset($this->seenNonces[$nonce])) {
            return false; // Replay of a handshake already accepted within the freshness window.
        }

        $this->seenNonces[$nonce] = $issuedAt;

        return true;
    }

    /**
     * Drop remembered nonces whose issue timestamp has aged past the freshness window: once
     * a handshake is too old to pass the freshness check it can never be accepted again, so
     * remembering its nonce serves no purpose. This bounds the set to the handshakes seen in
     * the last `freshnessWindow`.
     */
    private function evictStaleNonces(int $nowUnix): void
    {
        if ($this->seenNonces === []) {
            return;
        }

        foreach ($this->seenNonces as $nonce => $issuedAt) {
            if (abs($nowUnix - $issuedAt) > $this->freshnessWindowSeconds) {
                unset($this->seenNonces[$nonce]);
            }
        }
    }

    private function mac(Handshake $handshake, string $nonce, int $issuedAt): string
    {
        // Canonical JSON of the ordered identity claim — delimiter-injection-proof, since
        // every field (including the wire-supplied node map) is a distinct encoded value.
        $canonical = json_encode([
            'advertise' => $handshake->advertise,
            'clusterName' => $handshake->clusterName,
            'issuedAt' => $issuedAt,
            'node' => [
                'application' => $handshake->node['application'] ?? '',
                'cluster' => $handshake->node['cluster'] ?? '',
                'datacenter' => $handshake->node['datacenter'] ?? '',
                'node' => $handshake->node['node'] ?? '',
            ],
            'nonce' => $nonce,
            'protocolVersion' => $handshake->protocolVersion,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $canonical, $this->secret);
    }
}
