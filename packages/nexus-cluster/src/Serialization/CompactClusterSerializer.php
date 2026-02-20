<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Serialization;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use NoDiscard;
use Override;
use RuntimeException;

use function serialize;

use stdClass;

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
        $message = \serialize($envelope->message);

        return pack('n', strlen($target)) . $target
            . pack('n', strlen($sender)) . $sender
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

        // Target path
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', $data, $pos);
        $targetLen = $unpacked[1];
        $pos += 2;

        if ($pos + $targetLen > $len) {
            throw new RuntimeException('Compact envelope truncated at target path');
        }

        $targetStr = substr($data, $pos, $targetLen);
        $pos += $targetLen;

        // Sender path
        if ($pos + 2 > $len) {
            throw new RuntimeException('Compact envelope truncated at sender length');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', $data, $pos);
        $senderLen = $unpacked[1];
        $pos += 2;

        if ($pos + $senderLen > $len) {
            throw new RuntimeException('Compact envelope truncated at sender path');
        }

        $senderStr = substr($data, $pos, $senderLen);
        $pos += $senderLen;

        // Message
        $messageData = substr($data, $pos);

        /** @var mixed $message */
        $message = @\unserialize($messageData);

        if (!$message instanceof stdClass && !is_object($message)) {
            throw new RuntimeException('Failed to deserialize compact envelope message');
        }

        return Envelope::of(
            $message,
            ActorPath::fromString($senderStr),
            ActorPath::fromString($targetStr),
        );
    }
}
