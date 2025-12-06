<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Serialization;

use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;

/**
 * @psalm-api
 *
 * Serializes Envelopes for cross-worker transport.
 * Optimized for IPC performance, not for network/storage compatibility.
 */
interface ClusterSerializer
{
    #[NoDiscard]
    public function serialize(Envelope $envelope): string;

    #[NoDiscard]
    public function deserialize(string $data): Envelope;
}
