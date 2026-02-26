<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use JsonException;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Default envelope serializer that wraps a MessageSerializer.
 *
 * Serializes the envelope structure (sender, target, metadata) as JSON,
 * delegating inner message serialization to the wrapped MessageSerializer.
 */
final readonly class DefaultEnvelopeSerializer implements EnvelopeSerializer
{
    public function __construct(private MessageSerializer $messageSerializer) {}

    /**
     * @throws MessageSerializationException
     */
    #[Override]
    #[NoDiscard]
    public function serialize(Envelope $envelope): string
    {
        $serializedMessage = $this->messageSerializer->serialize($envelope->message);

        $metadataArray = $envelope->metadata;

        $payload = [
            'message' => $serializedMessage,
            'messageType' => $envelope->message::class,
            'metadata' => $metadataArray,
            'sender' => (string) $envelope->sender,
            'target' => (string) $envelope->target,
            'requestId' => $envelope->requestId,
            'correlationId' => $envelope->correlationId,
            'causationId' => $envelope->causationId,
        ];

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MessageSerializationException(
                $envelope->message::class,
                'Failed to encode envelope: ' . $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @throws MessageDeserializationException
     */
    #[Override]
    #[NoDiscard]
    public function deserialize(string $data): Envelope
    {
        try {
            /** @var array{
             *  sender: string,
             *  target: string,
             *  metadata: array<string, string>,
             *  messageType: string,
             *  message: string,
             *  requestId: string,
             *  correlationId: string,
             *  causationId: string
             * } $payload */
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MessageDeserializationException('Envelope', 'Failed to decode envelope: ' . $e->getMessage(), $e);
        }

        $message = $this->messageSerializer->deserialize($payload['message'], $payload['messageType']);

        try {
            $sender = ActorPath::fromString($payload['sender']);
            $target = ActorPath::fromString($payload['target']);
        } catch (Throwable $e) {
            throw new MessageDeserializationException('Envelope', 'Invalid actor path: ' . $e->getMessage(), $e);
        }

        return new Envelope(
            message: $message,
            sender: $sender,
            target: $target,
            requestId: $payload['requestId'],
            correlationId: $payload['correlationId'],
            causationId: $payload['causationId'],
            metadata: $payload['metadata'],
        );
    }
}
