<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;

/**
 * @psalm-api
 *
 * Serializes and deserializes Envelope instances.
 */
interface EnvelopeSerializer
{
    /**
     * Serializes an Envelope to a string representation.
     *
     * @throws MessageSerializationException
     */
    #[NoDiscard]
    public function serialize(Envelope $envelope): string;

    /**
     * Deserializes a string representation back into an Envelope.
     *
     * @throws MessageDeserializationException
     */
    #[NoDiscard]
    public function deserialize(string $data): Envelope;
}
