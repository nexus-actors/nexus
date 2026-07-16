<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Serialization;

use JsonException;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\ReplyToStamp;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_keys;
use function class_exists;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * Messenger SerializerInterface backed by a Nexus MessageSerializer.
 *
 * Bodies are (de)serialized by the injected Nexus serializer; the message type
 * travels in the "type" header. Encode and decode behave asymmetrically:
 * encode uses the registered #[MessageType] name when available, falling back
 * to the FQCN; decode requires the header value to be registered in the
 * TypeRegistry and throws MessageDecodingFailedException otherwise. To
 * deliberately accept a FQCN header on decode, register the class as its own
 * type name: $registry->register(Foo::class, Foo::class).
 *
 * The bridge's own stamps round-trip as plain string headers. Other stamps are
 * NOT preserved in v1 — swap in any Symfony SerializerInterface if you need
 * full stamp fidelity or interop with non-Nexus producers.
 *
 * @psalm-api
 */
final readonly class NexusMessengerSerializer implements SerializerInterface
{
    private const string HEADER_CORRELATION_ID = 'X-Nexus-Correlation-Id';
    private const string HEADER_REPLY_TO = 'X-Nexus-Reply-To';
    private const string HEADER_SOURCE_PATH = 'X-Nexus-Source-Path';
    private const string HEADER_TARGET_PATH = 'X-Nexus-Target-Path';
    private const string HEADER_TRACE_CONTEXT = 'X-Nexus-Trace-Context';
    private const string HEADER_TYPE = 'type';

    public function __construct(private MessageSerializer $messages, private TypeRegistry $types,) {
    }

    /**
     * @param array<string, mixed> $encodedEnvelope
     */
    #[Override]
    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        $headers = $encodedEnvelope['headers'] ?? [];

        if (!is_string($body) || !is_array($headers)) {
            throw new MessageDecodingFailedException(
                'Encoded envelope must contain a string "body" and array "headers".',
            );
        }

        /** @var array<string, mixed> $headers */
        $type = $headers[self::HEADER_TYPE] ?? null;

        if (!is_string($type) || $type === '') {
            throw new MessageDecodingFailedException('Encoded envelope is missing the "type" header.');
        }

        $class = $this->types->classForName($type);

        if ($class === null) {
            throw new MessageDecodingFailedException(
                sprintf(
                    'Message type "%s" is not registered; register it via TypeRegistry (e.g. registerFromAttribute() or register()).',
                    $type,
                ),
            );
        }

        if (!class_exists($class)) {
            throw new MessageDecodingFailedException(
                sprintf('Message type "%s" does not resolve to a known class.', $type),
            );
        }

        try {
            $message = $this->messages->deserialize($body, $class);
        } catch (MessageDeserializationException $e) {
            throw new MessageDecodingFailedException($e->getMessage(), 0, $e);
        }

        return new Envelope($message, $this->stampsFromHeaders($headers));
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    #[Override]
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        return [
            'body' => $this->messages->serialize($message),
            'headers' => $this->headersFor($envelope, $message),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Envelope $envelope, object $message): array
    {
        $headers = [self::HEADER_TYPE => $this->types->nameForClass($message::class) ?? $message::class];
        $correlationId = $envelope->last(CorrelationIdStamp::class);

        if ($correlationId instanceof CorrelationIdStamp) {
            $headers[self::HEADER_CORRELATION_ID] = $correlationId->id;
        }

        $replyTo = $envelope->last(ReplyToStamp::class);

        if ($replyTo instanceof ReplyToStamp) {
            $headers[self::HEADER_REPLY_TO] = $replyTo->channel;
        }

        $source = $envelope->last(SourceActorPathStamp::class);

        if ($source instanceof SourceActorPathStamp) {
            $headers[self::HEADER_SOURCE_PATH] = $source->path;
        }

        $target = $envelope->last(TargetActorPathStamp::class);

        if ($target instanceof TargetActorPathStamp) {
            $headers[self::HEADER_TARGET_PATH] = $target->path;
        }

        $traceStamp = $envelope->last(TraceContextStamp::class);

        if ($traceStamp instanceof TraceContextStamp) {
            $headers[self::HEADER_TRACE_CONTEXT] = json_encode($traceStamp->carrier, JSON_THROW_ON_ERROR);
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<StampInterface>
     */
    private function stampsFromHeaders(array $headers): array
    {
        $stamps = [];
        $correlationId = $this->stringHeader($headers, self::HEADER_CORRELATION_ID);

        if ($correlationId !== null && $correlationId !== '') {
            $stamps[] = new CorrelationIdStamp($correlationId);
        }

        $replyTo = $this->stringHeader($headers, self::HEADER_REPLY_TO);

        if ($replyTo !== null && $replyTo !== '') {
            $stamps[] = new ReplyToStamp($replyTo);
        }

        $source = $this->stringHeader($headers, self::HEADER_SOURCE_PATH);

        if ($source !== null && $source !== '') {
            $stamps[] = new SourceActorPathStamp($source);
        }

        $target = $this->stringHeader($headers, self::HEADER_TARGET_PATH);

        if ($target !== null && $target !== '') {
            $stamps[] = new TargetActorPathStamp($target);
        }

        $traceContext = $this->stringHeader($headers, self::HEADER_TRACE_CONTEXT);

        if ($traceContext !== null && $traceContext !== '') {
            try {
                /** @var array<array-key, mixed>|scalar|null $decoded json_decode(assoc: true) wire data — entries validated below */
                $decoded = json_decode($traceContext, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    $carrier = [];

                    foreach (array_keys($decoded) as $key) {
                        if (is_string($key) && is_string($decoded[$key])) {
                            $carrier[$key] = $decoded[$key];
                        }
                    }

                    if (count($carrier) > 0) {
                        $stamps[] = new TraceContextStamp($carrier);
                    }
                }
            } catch (JsonException) {
                // Silently skip malformed trace context — telemetry must never break decode.
            }
        }

        return $stamps;
    }

    /**
     * Untrusted-wire boundary: returns the header value only when it is a string.
     *
     * @param array<string, mixed> $headers
     */
    private function stringHeader(array $headers, string $key): ?string
    {
        return isset($headers[$key]) && is_string($headers[$key])
            ? $headers[$key]
            : null;
    }
}
