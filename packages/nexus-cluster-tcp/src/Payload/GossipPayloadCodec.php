<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

use function is_string;

/**
 * @psalm-api
 *
 * Hand-rolled msgpack codec for the {@see GossipPayload} control frame — no Valinor on the cluster
 * wire path. Gossip is the steady-state heartbeat (sent to every Up peer each interval), so keeping
 * it off the reflection-driven mapper matters most here. Map keys are the property names, so the
 * bytes match the previous Valinor-backed encoding and nodes on either codec interop.
 */
final readonly class GossipPayloadCodec
{
    private const string TYPE = 'cluster.gossip';

    public function __construct(private MsgpackCodec $codec = new MsgpackCodec()) {}

    /**
     * @throws MessageSerializationException When msgpack encoding fails.
     */
    public function pack(GossipPayload $gossip): string
    {
        try {
            return $this->codec->pack([
                'members' => $gossip->members,
                'registrations' => $gossip->registrations,
            ]);
        } catch (Throwable $e) {
            throw new MessageSerializationException(GossipPayload::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws MessageDeserializationException
     */
    public function unpack(string $bytes): GossipPayload
    {
        $reader = MsgpackReader::from($bytes, $this->codec, self::TYPE);

        $members = [];

        foreach ($reader->listOfMaps('members') as $entry) {
            $member = MsgpackReader::fromArray($entry, self::TYPE);
            $members[] = [
                'address' => $member->string('address'),
                'endpoint' => $member->string('endpoint'),
                'incarnation' => $member->int('incarnation'),
                'status' => $member->int('status'),
            ];
        }

        $registrations = [];

        foreach ($reader->listOfMaps('registrations') as $entry) {
            $registration = [];

            /** @psalm-suppress MixedAssignment Validated entry-by-entry below. */
            foreach ($entry as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new MessageDeserializationException(
                        self::TYPE,
                        'Gossip registration entries must be string-to-string.',
                    );
                }

                $registration[$key] = $value;
            }

            $registrations[] = $registration;
        }

        return new GossipPayload($members, $registrations);
    }
}
