<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

/**
 * @psalm-api
 *
 * Hand-rolled msgpack codec for the {@see HandshakeAck} control frame — no Valinor on the cluster
 * wire path. Map keys are property names, so the bytes match the previous Valinor-backed encoding.
 */
final readonly class HandshakeAckCodec
{
    private const string TYPE = 'cluster.handshake_ack';

    public function __construct(private MsgpackCodec $codec = new MsgpackCodec()) {}

    /**
     * @throws MessageSerializationException When msgpack encoding fails.
     */
    public function pack(HandshakeAck $ack): string
    {
        try {
            return $this->codec->pack([
                'accepted' => $ack->accepted,
                'reason' => $ack->reason,
                'view' => $ack->view,
            ]);
        } catch (Throwable $e) {
            throw new MessageSerializationException(HandshakeAck::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws \Monadial\Nexus\Serialization\Exception\MessageDeserializationException
     */
    public function unpack(string $bytes): HandshakeAck
    {
        $reader = MsgpackReader::from($bytes, $this->codec, self::TYPE);

        return new HandshakeAck(
            accepted: $reader->bool('accepted'),
            reason: $reader->nullableString('reason'),
            view: $reader->stringMap('view'),
        );
    }
}
