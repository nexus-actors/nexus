<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Serialization;

use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;
use Override;
use RuntimeException;

use function serialize;
use function unserialize;

/**
 * @psalm-api
 *
 * Uses PHP serialize()/unserialize() for maximum IPC throughput.
 * Benchmarked at 1.46M roundtrips/sec for typical actor messages.
 */
final readonly class PhpNativeClusterSerializer implements ClusterSerializer
{
    #[Override]
    #[NoDiscard]
    public function serialize(Envelope $envelope): string
    {
        return serialize($envelope);
    }

    #[Override]
    #[NoDiscard]
    public function deserialize(string $data): Envelope
    {
        /** @var mixed $result */
        $result = @unserialize($data);

        if (!$result instanceof Envelope) {
            throw new RuntimeException('Failed to deserialize cluster envelope');
        }

        return $result;
    }
}
