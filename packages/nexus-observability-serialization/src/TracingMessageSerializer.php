<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Serialization;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Serialization\MessageSerializer;
use NoDiscard;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

use function hrtime;
use function strlen;
use function strrpos;
use function substr;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see MessageSerializer}. Adds an Internal span and metrics
 * per serialize/deserialize operation. Serializer errors propagate (recorded on the span
 * first); telemetry errors never break serialization. Delegates directly when
 * observability is disabled and no logger is present.
 *
 * Example:
 * ```php
 * $serializer = new TracingMessageSerializer(
 *     PhpNativeSerializer::forTrustedData(),
 *     $observability,
 *     $logger,
 * );
 * ```
 */
final class TracingMessageSerializer implements MessageSerializer
{
    private ?Counter $operations = null;

    private ?Counter $failures = null;

    private ?Histogram $bytes = null;

    private ?Histogram $duration = null;

    public function __construct(
        private readonly MessageSerializer $inner,
        private readonly Observability $observability,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    #[NoDiscard]
    public function serialize(object $message): string
    {
        if (!$this->observability->isEnabled() && $this->logger === null) {
            return $this->inner->serialize($message);
        }

        $type = $message::class;
        $serializer = $this->shortName($this->inner::class);

        $span = $this->startSpan('serialization.serialize', [
            'nexus.message.type' => $type,
            'nexus.serializer' => $serializer,
        ]);
        $start = hrtime(true);

        try {
            $result = $this->inner->serialize($message);
            $this->safely(function () use ($type, $serializer, $result): void {
                $this->operationsCounter()->add(1, [
                    'nexus.message.type' => $type,
                    'nexus.serializer' => $serializer,
                    'operation' => 'serialize',
                ]);
                $this->bytesHistogram()->record(strlen($result), ['operation' => 'serialize']);
            });

            return $result;
        } catch (Throwable $e) {
            $this->recordError($span, $e);
            $this->safely(fn(): mixed => $this->failuresCounter()->add(1, [
                'nexus.message.type' => $type,
                'nexus.serializer' => $serializer,
                'operation' => 'serialize',
            ]));
            $this->logger?->warning('Serialization failed', [
                'exception' => $e,
                'message_type' => $type,
                'serializer' => $serializer,
            ]);

            throw $e;
        } finally {
            $this->safely(fn(): mixed => $this->durationHistogram()->record(
                (hrtime(true) - $start) / 1_000_000,
                ['operation' => 'serialize'],
            ));
            $this->safely(static fn(): mixed => $span?->end());
        }
    }

    #[Override]
    #[NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        if (!$this->observability->isEnabled() && $this->logger === null) {
            return $this->inner->deserialize($data, $type);
        }

        $serializer = $this->shortName($this->inner::class);

        $span = $this->startSpan('serialization.deserialize', [
            'nexus.message.type' => $type,
            'nexus.serializer' => $serializer,
        ]);
        $start = hrtime(true);

        try {
            $result = $this->inner->deserialize($data, $type);
            $this->safely(function () use ($type, $serializer, $data): void {
                $this->operationsCounter()->add(1, [
                    'nexus.message.type' => $type,
                    'nexus.serializer' => $serializer,
                    'operation' => 'deserialize',
                ]);
                $this->bytesHistogram()->record(strlen($data), ['operation' => 'deserialize']);
            });

            return $result;
        } catch (Throwable $e) {
            $this->recordError($span, $e);
            $this->safely(fn(): mixed => $this->failuresCounter()->add(1, [
                'nexus.message.type' => $type,
                'nexus.serializer' => $serializer,
                'operation' => 'deserialize',
            ]));
            $this->logger?->warning('Deserialization failed', [
                'exception' => $e,
                'message_type' => $type,
                'serializer' => $serializer,
            ]);

            throw $e;
        } finally {
            $this->safely(fn(): mixed => $this->durationHistogram()->record(
                (hrtime(true) - $start) / 1_000_000,
                ['operation' => 'deserialize'],
            ));
            $this->safely(static fn(): mixed => $span?->end());
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function startSpan(string $name, array $attributes): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan($name, SpanKind::Internal, $attributes);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function operationsCounter(): Counter
    {
        return $this->operations ??= $this->observability->meter()->counter(
            'nexus.serialization.operations',
            '{operation}',
            'Number of serialization operations',
        );
    }

    private function failuresCounter(): Counter
    {
        return $this->failures ??= $this->observability->meter()->counter(
            'nexus.serialization.failures',
            '{operation}',
            'Number of failed serialization operations',
        );
    }

    private function bytesHistogram(): Histogram
    {
        return $this->bytes ??= $this->observability->meter()->histogram(
            'nexus.serialization.bytes',
            'By',
            'Size of serialized/deserialized payloads',
        );
    }

    private function durationHistogram(): Histogram
    {
        return $this->duration ??= $this->observability->meter()->histogram(
            'nexus.serialization.duration',
            'ms',
            'Duration of serialization operations',
        );
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false
            ? substr($fqcn, $pos + 1)
            : $fqcn;
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break serialization.
        }
    }
}
