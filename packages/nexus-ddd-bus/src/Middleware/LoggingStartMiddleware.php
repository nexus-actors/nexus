<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 *
 * Writes the start-of-handling INFO log with structured context
 * (`messageId`, `messageType`, `causationId`, `correlationId`). The
 * raw payload is emitted at DEBUG only when explicitly enabled — the
 * default is deny — and any `#[Sensitive]` property is redacted by
 * the injected `PayloadRedactor`.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class LoggingStartMiddleware implements Middleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PayloadRedactor $redactor,
        private readonly bool $logPayloadAtDebug = false,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $context = [
            'causationId' => $envelope->metadata->causationId
                ->map(static fn(MessageId $id): string => $id->value())
                ->getOrElse(''),
            'correlationId' => $envelope->metadata->correlationId
                ->map(static fn(MessageId $id): string => $id->value())
                ->getOrElse(''),
            'messageId' => $envelope->metadata->id->value(),
            'messageType' => $envelope->message::class,
        ];

        if ($this->logPayloadAtDebug) {
            $this->logger->debug(
                'ddd.command.dispatched.payload',
                [...$context, 'payload' => $this->redactor->redact($envelope->message)],
            );
        }

        $this->logger->info('ddd.command.dispatched', $context);

        return $next($envelope);
    }
}
