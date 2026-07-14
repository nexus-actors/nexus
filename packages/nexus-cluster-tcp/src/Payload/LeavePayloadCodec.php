<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

/**
 * @psalm-api
 *
 * Hand-rolled msgpack codec for the {@see LeavePayload} control frame — no Valinor on the cluster
 * wire path. Map key is the property name, so the bytes match the previous Valinor-backed encoding.
 */
final readonly class LeavePayloadCodec
{
    private const string TYPE = 'cluster.leave';

    public function __construct(private MsgpackCodec $codec = new MsgpackCodec()) {}

    /**
     * @throws MessageSerializationException When msgpack encoding fails.
     */
    public function pack(LeavePayload $leave): string
    {
        try {
            return $this->codec->pack(['node' => $leave->node]);
        } catch (Throwable $e) {
            throw new MessageSerializationException(LeavePayload::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws \Monadial\Nexus\Serialization\Exception\MessageDeserializationException
     */
    public function unpack(string $bytes): LeavePayload
    {
        $reader = MsgpackReader::from($bytes, $this->codec, self::TYPE);

        return new LeavePayload(node: $reader->string('node'));
    }
}
