<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Runtime\Duration;

use function abs;
use function bin2hex;
use function hash_equals;
use function hash_hmac;
use function json_encode;
use function random_bytes;
use function time;

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
 * cannot be replayed indefinitely; comparison is constant-time.
 */
final readonly class HandshakeAuthenticator
{
    private int $freshnessWindowSeconds;

    public function __construct(private string $secret, ?Duration $freshnessWindow = null)
    {
        $this->freshnessWindowSeconds = ($freshnessWindow ?? Duration::seconds(60))->toSeconds();
    }

    /**
     * Return a copy of `$handshake` carrying a fresh nonce, issue timestamp, and HMAC.
     */
    public function sign(Handshake $handshake): Handshake
    {
        $nonce = bin2hex(random_bytes(16));
        $issuedAt = time();

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
     * Whether `$handshake` carries a valid, fresh signature for this secret.
     * A handshake with no signature fields always fails when a secret is enforced.
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

        return hash_equals($this->mac($handshake, $nonce, $issuedAt), $mac);
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
