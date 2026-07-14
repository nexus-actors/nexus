<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

/**
 * @psalm-api
 *
 * Hand-rolled msgpack codec for the {@see Handshake} control frame — no Valinor on the cluster wire
 * path. The map keys are the property names, so the bytes are identical to what the generic
 * Valinor-backed serializer produced; a node on either codec interops.
 */
final readonly class HandshakeCodec
{
    private const string TYPE = 'cluster.handshake';

    public function __construct(private MsgpackCodec $codec = new MsgpackCodec()) {}

    /**
     * @throws MessageSerializationException When msgpack encoding fails.
     */
    public function pack(Handshake $handshake): string
    {
        try {
            return $this->codec->pack([
                'advertise' => $handshake->advertise,
                'clusterName' => $handshake->clusterName,
                'issuedAt' => $handshake->issuedAt,
                'mac' => $handshake->mac,
                'node' => $handshake->node,
                'nonce' => $handshake->nonce,
                'protocolVersion' => $handshake->protocolVersion,
            ]);
        } catch (Throwable $e) {
            throw new MessageSerializationException(Handshake::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws \Monadial\Nexus\Serialization\Exception\MessageDeserializationException
     */
    public function unpack(string $bytes): Handshake
    {
        $reader = MsgpackReader::from($bytes, $this->codec, self::TYPE);

        return new Handshake(
            clusterName: $reader->string('clusterName'),
            node: $reader->stringMap('node'),
            advertise: $reader->string('advertise'),
            protocolVersion: $reader->intOr('protocolVersion', 1),
            nonce: $reader->nullableString('nonce'),
            issuedAt: $reader->nullableInt('issuedAt'),
            mac: $reader->nullableString('mac'),
        );
    }
}
