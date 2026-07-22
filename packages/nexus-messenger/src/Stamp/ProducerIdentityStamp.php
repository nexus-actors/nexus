<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Provenance stamp: the logical identity of the producer that published a message.
 *
 * Read by TargetAuthorizer implementations (e.g. MapTargetAuthorizer) to authorize
 * producer → target routing on the consumer side, and round-tripped by
 * NexusMessengerSerializer as the X-Nexus-Producer-Identity header.
 *
 * **Trust boundary.** A stamp set by the producer proves origin only as far as the
 * producer is trusted. Across mutually untrusted producers this value must be
 * established or validated at a trusted boundary — an authenticated transport, a
 * broker ACL that stamps identity, or a signed envelope — otherwise a producer can
 * assert any identity. The authorizer enforces the ACL; the trust in the identity
 * comes from the boundary that set it.
 *
 * @psalm-api
 */
final readonly class ProducerIdentityStamp implements StampInterface
{
    public function __construct(public string $identity) {}
}
