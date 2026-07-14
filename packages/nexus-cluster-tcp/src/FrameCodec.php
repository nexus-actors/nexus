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
     *
     * Enforces `maxFrameSize` on the SEND side, not just on decode: an oversized frame is rejected
     * here — locally, before a single byte reaches the socket — so the caller (e.g. `ClusterRef::tell`)
     * sees the failure and the peer link stays intact. Without this guard the oversized frame would be
     * written, the remote would reject it at the length prefix and tear the whole link down, dropping
     * every in-flight gossip/ask/message on it and forcing a reconnect + full re-handshake — a
     * disproportionate blast radius for one too-large message.
     *
     * @throws ProtocolException when the encoded body would exceed `$maxFrameSize`.
     */
    public function encode(Frame $frame): string
    {
        $payload = $frame->payload;
        $bodyLength = 1 + strlen($payload);

        if ($bodyLength > $this->maxFrameSize) {
            throw new ProtocolException(
                sprintf(
                    'Frame body length %d exceeds the maximum allowed size of %d bytes; the frame was '
                    . 'not sent and the peer link is left intact. Reduce the message size or raise the '
                    . 'limit via ClusterTopology::withMaxFrameSize().',
                    $bodyLength,
                    $this->maxFrameSize,
                ),
            );
        }

        return pack('N', $bodyLength) . chr($frame->type->value) . $payload;
    }

    /**
     * Incrementally decode as many complete frames as `$buffer` contains.
     *
     * Any trailing partial frame (including fewer than 4 bytes for the length
     * prefix, or a declared body not yet fully arrived) is returned in `rest`
     * unchanged.
     *
     * Unknown frame types are SKIPPED (their length-delimited body is consumed and the stream stays
     * synchronized), not treated as errors: the length prefix makes every frame self-delimiting, so
     * an older node can tolerate a frame type a newer protocol version introduced instead of tearing
     * the link down. This forward-compatibility is why a future frame type can be added without a
     * fleet-wide flag-day. Malformed FRAMING (a length over `$maxFrameSize` or below the 1-byte
     * minimum) still throws, because that indicates real corruption rather than an unknown-but-well-
     * framed frame.
     *
     * @return array{frames: list<Frame>, rest: string}
     *
     * @throws ProtocolException when a declared frame length exceeds `$maxFrameSize` or is below the minimum.
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

            // A recognised frame is decoded; an unknown-but-well-framed type is skipped (forward
            // compatibility — see method docblock). Either way the buffer advances past the whole
            // length-delimited frame, so the stream stays synchronized.
            if ($frameType !== null) {
                $payloadLength = $bodyLength - 1;
                $payload = $payloadLength > 0
                    ? substr($buffer, 5, $payloadLength)
                    : '';

                $frames[] = new Frame($frameType, $payload);
            }

            $buffer = substr($buffer, 4 + $bodyLength);
        }

        return ['frames' => $frames, 'rest' => $buffer];
    }
}
