<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

/**
 * @psalm-api
 *
 * Hand-rolled msgpack codec for the {@see MessagePayload} envelope — the cluster's
 * per-message hot path.
 *
 * The generic {@see \Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer}
 * routes every object through Valinor (normalizer out, TreeMapper in). That is the right
 * tool for arbitrary user message types, but the envelope is a fixed six-field internal
 * VO and the mapper's reflection-driven hydration dominated the receive path (~12 µs of a
 * ~32 µs per-message budget in the loopback benchmark). This codec packs and unpacks the
 * same msgpack map directly, cutting the envelope cost to raw msgpack + validation.
 *
 * Wire compatibility: both codecs produce a msgpack map with the same six keys, and both
 * readers resolve fields by key (never by position), so nodes using either codec interop
 * freely — covered by cross-decoding tests in both directions.
 *
 * Trust boundary: `unpack()` consumes bytes straight off the network. Every field is
 * type-checked before the VO is constructed; any mismatch throws
 * {@see MessageDeserializationException}, which the frame ingress treats as an
 * undecodable frame (dropped and logged, never routed).
 */
final readonly class MessagePayloadCodec
{
    /** The wire type name of the envelope, matching MessagePayload's #[MessageType]. */
    private const string TYPE = 'cluster.message';

    public function __construct(private MsgpackCodec $codec = new MsgpackCodec()) {}

    /**
     * @throws MessageSerializationException When msgpack encoding fails.
     */
    public function pack(MessagePayload $payload): string
    {
        try {
            return $this->codec->pack([
                'body' => $payload->body,
                'correlationId' => $payload->correlationId,
                'messageType' => $payload->messageType,
                'replyPath' => $payload->replyPath,
                'targetPath' => $payload->targetPath,
                'trace' => $payload->trace,
            ]);
        } catch (Throwable $e) {
            throw new MessageSerializationException(MessagePayload::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws MessageDeserializationException When the bytes are not a well-formed envelope.
     */
    public function unpack(string $bytes): MessagePayload
    {
        // Route through the shared MsgpackReader used by the control-frame codecs: same by-key,
        // type-checked, forward-compatible field resolution (unknown keys ignored), one place to
        // maintain the wire trust boundary instead of a bespoke parser per payload.
        $reader = MsgpackReader::from($bytes, $this->codec, self::TYPE);

        return new MessagePayload(
            targetPath: $reader->string('targetPath'),
            messageType: $reader->string('messageType'),
            body: $reader->string('body'),
            correlationId: $reader->nullableString('correlationId'),
            replyPath: $reader->nullableString('replyPath'),
            trace: $reader->stringMap('trace'),
        );
    }
}
