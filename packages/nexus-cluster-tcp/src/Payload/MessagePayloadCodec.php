<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

use function is_array;
use function is_string;

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
        try {
            $data = $this->codec->unpack($bytes);
        } catch (Throwable $e) {
            throw new MessageDeserializationException(self::TYPE, $e->getMessage(), $e);
        }

        $targetPath = $data['targetPath'] ?? null;
        $messageType = $data['messageType'] ?? null;
        $body = $data['body'] ?? null;
        $correlationId = $data['correlationId'] ?? null;
        $replyPath = $data['replyPath'] ?? null;
        $trace = $data['trace'] ?? [];

        if (!is_string($targetPath) || !is_string($messageType) || !is_string($body)) {
            throw new MessageDeserializationException(
                self::TYPE,
                'Envelope is missing a required string field (targetPath, messageType, or body).',
            );
        }

        if ($correlationId !== null && !is_string($correlationId)) {
            throw new MessageDeserializationException(self::TYPE, 'Envelope correlationId must be a string or null.');
        }

        if ($replyPath !== null && !is_string($replyPath)) {
            throw new MessageDeserializationException(self::TYPE, 'Envelope replyPath must be a string or null.');
        }

        if (!is_array($trace)) {
            throw new MessageDeserializationException(self::TYPE, 'Envelope trace must be a map.');
        }

        $traceHeaders = [];

        /** @psalm-suppress MixedAssignment Wire input is validated entry-by-entry below. */
        foreach ($trace as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new MessageDeserializationException(
                    self::TYPE,
                    'Envelope trace entries must be string-to-string.',
                );
            }

            $traceHeaders[$key] = $value;
        }

        return new MessagePayload(
            targetPath: $targetPath,
            messageType: $messageType,
            body: $body,
            correlationId: $correlationId,
            replyPath: $replyPath,
            trace: $traceHeaders,
        );
    }
}
