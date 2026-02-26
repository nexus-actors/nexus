<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Serialization;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;
use Override;
use RuntimeException;
use stdClass;

use function serialize;
use function unserialize;

/**
 * @psalm-api
 *
 * Compact binary serialization for cluster IPC.
 *
 * Only serializes the message object — sends actor paths as raw UTF-8 strings.
 * ~6x smaller wire format than PhpNativeClusterSerializer for typical messages.
 *
 * Wire format:
 *   [2 bytes: target path length (network byte order)]
 *   [N bytes: target path string]
 *   [2 bytes: sender path length (network byte order)]
 *   [N bytes: sender path string]
 *   [2 bytes: request id length]
 *   [N bytes: request id]
 *   [2 bytes: correlation id length]
 *   [N bytes: correlation id]
 *   [2 bytes: causation id length]
 *   [N bytes: causation id]
 *   [remaining bytes: serialize($message)]
 */
final readonly class CompactClusterSerializer implements ClusterSerializer
{
    #[Override]
    #[NoDiscard]
    public function serialize(Envelope $envelope): string
    {
        $target = (string) $envelope->target;
        $sender = (string) $envelope->sender;
        $requestId = $envelope->requestId;
        $correlationId = $envelope->correlationId;
        $causationId = $envelope->causationId;
        $message = serialize($envelope->message);

        return pack('n', strlen($target)) . $target
            . pack('n', strlen($sender)) . $sender
            . pack('n', strlen($requestId)) . $requestId
            . pack('n', strlen($correlationId)) . $correlationId
            . pack('n', strlen($causationId)) . $causationId
            . $message;
    }

    #[Override]
    #[NoDiscard]
    public function deserialize(string $data): Envelope
    {
        $pos = 0;
        $len = strlen($data);

        if ($len < 4) {
            throw new RuntimeException('Compact envelope too short');
        }

        $targetStr = $this->unpackString($data, $pos, $len, 'target path');
        $senderStr = $this->unpackString($data, $pos, $len, 'sender path');
        $requestId = $this->unpackString($data, $pos, $len, 'request id');
        $correlationId = $this->unpackString($data, $pos, $len, 'correlation id');
        $causationId = $this->unpackString($data, $pos, $len, 'causation id');

        // Message
        $messageData = substr($data, $pos);

        /** @var mixed $message */
        $message = @unserialize($messageData);

        if (!$message instanceof stdClass && !is_object($message)) {
            throw new RuntimeException('Failed to deserialize compact envelope message');
        }

        return new Envelope(
            message: $message,
            sender: ActorPath::fromString($senderStr),
            target: ActorPath::fromString($targetStr),
            requestId: $requestId,
            correlationId: $correlationId,
            causationId: $causationId,
        );
    }

    private function unpackString(string $data, int &$pos, int $len, string $segment): string
    {
        if ($pos + 2 > $len) {
            throw new RuntimeException("Compact envelope truncated at {$segment} length");
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', $data, $pos);
        $segmentLen = $unpacked[1];
        $pos += 2;

        if ($pos + $segmentLen > $len) {
            throw new RuntimeException("Compact envelope truncated at {$segment}");
        }

        $value = substr($data, $pos, $segmentLen);
        $pos += $segmentLen;

        return $value;
    }
}
