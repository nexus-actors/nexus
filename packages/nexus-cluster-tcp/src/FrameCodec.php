<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

use Monadial\Nexus\Cluster\Tcp\Exception\ProtocolException;

use function chr;
use function ord;
use function pack;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * @psalm-api
 *
 * Encodes and incrementally decodes length-prefixed cluster TCP frames.
 *
 * Wire format (per frame):
 *   [4 bytes big-endian uint32: body length] [1 byte: FrameType value] [N bytes: msgpack payload]
 *
 * The 4-byte length covers the type byte plus the payload — i.e.
 * `body_length = 1 + strlen($payload)`.
 *
 * `decodeStream` is incremental: it consumes as many complete frames as the
 * buffer contains and returns any trailing partial frame in `rest`. Callers
 * feed `rest` back on the next read, enabling byte-by-byte reassembly without
 * copies beyond the buffer.
 *
 * `maxFrameSize` bounds the declared body length: a peer that declares a larger
 * frame is rejected at the 4-byte prefix, before its body is buffered, so per-link
 * reassembly memory stays bounded by this value. It is wired from
 * `ClusterTopology::withMaxFrameSize()` through `SwooleMeshTransport`/`SwoolePeerLink`.
 */
final class FrameCodec
{
    public function __construct(private int $maxFrameSize = 8 * 1024 * 1024) {}

    /**
     * Encode a Frame into its length-prefixed wire representation.
     */
    public function encode(Frame $frame): string
    {
        $payload = $frame->payload;
        $bodyLength = 1 + strlen($payload);

        return pack('N', $bodyLength) . chr($frame->type->value) . $payload;
    }

    /**
     * Incrementally decode as many complete frames as `$buffer` contains.
     *
     * Any trailing partial frame (including fewer than 4 bytes for the length
     * prefix, or a declared body not yet fully arrived) is returned in `rest`
     * unchanged.
     *
     * @return array{frames: list<Frame>, rest: string}
     *
     * @throws ProtocolException when a declared frame length exceeds `$maxFrameSize`
     *                           or a frame contains an unknown type byte.
     */
    public function decodeStream(string $buffer): array
    {
        /** @var list<Frame> $frames */
        $frames = [];

        while (true) {
            if (strlen($buffer) < 4) {
                break;
            }

            /** @var array{length: int} $unpacked */
            $unpacked = unpack('Nlength', $buffer);
            $bodyLength = $unpacked['length'];

            if ($bodyLength > $this->maxFrameSize) {
                throw new ProtocolException(
                    sprintf(
                        'Frame body length %d exceeds maximum allowed size of %d bytes.',
                        $bodyLength,
                        $this->maxFrameSize,
                    ),
                );
            }

            if ($bodyLength < 1) {
                throw new ProtocolException(
                    sprintf('Frame body length %d is invalid; minimum is 1 (type byte).', $bodyLength),
                );
            }

            // Need 4-byte length prefix + body
            if (strlen($buffer) < 4 + $bodyLength) {
                break;
            }

            $typeByte = ord($buffer[4]);
            $frameType = FrameType::tryFrom($typeByte);

            if ($frameType === null) {
                throw new ProtocolException(
                    sprintf('Unknown frame type byte 0x%02x.', $typeByte),
                );
            }

            $payloadLength = $bodyLength - 1;
            $payload = $payloadLength > 0
                ? substr($buffer, 5, $payloadLength)
                : '';

            $frames[] = new Frame($frameType, $payload);
            $buffer = substr($buffer, 4 + $bodyLength);
        }

        return ['frames' => $frames, 'rest' => $buffer];
    }
}
