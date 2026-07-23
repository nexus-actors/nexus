<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;

/**
 * @psalm-api
 *
 * Authenticates cluster peers via handshake signing and verification.
 *
 * The admission capability of the transport SPI (spec §3.4.2). Implementations are
 * stateful: the single instance per node maintains the replay-guard state. Nonce-replay
 * correctness depends on exactly ONE authenticator instance per node.
 */
interface PeerAuthenticator
{
    /**
     * Return a copy of `$handshake` carrying a fresh nonce, issue timestamp, and HMAC.
     */
    public function sign(Handshake $handshake): Handshake;

    /**
     * Whether `$handshake` carries a valid, fresh, non-replayed signature for this secret.
     */
    public function verify(Handshake $handshake, int $nowUnix): bool;
}
