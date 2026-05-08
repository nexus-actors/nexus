<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Serialization;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Wire-format gateway between Envelope<object> and SerializedMessage.
 * Concrete impls live in transport-adapter packages (e.g., Symfony
 * Messenger, AMQP, Redis Stream).
 */
interface MessageSerializer
{
    /**
     * @param Envelope<object> $envelope
     */
    public function serialize(Envelope $envelope): SerializedMessage;

    /**
     * @return Envelope<object>
     */
    public function deserialize(SerializedMessage $serialized): Envelope;
}
