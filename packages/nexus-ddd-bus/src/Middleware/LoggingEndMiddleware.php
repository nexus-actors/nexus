<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Symmetric exit for `LoggingStartMiddleware`. Emits a single INFO record
 * on success (`ddd.command.completed`) or WARNING on failure
 * (`ddd.command.failed`) carrying the same envelope identifiers as the
 * start log so log scraping can correlate the dispatch lifecycle.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class LoggingEndMiddleware implements Middleware
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        try {
            $result = $next($envelope);
        } catch (Throwable $e) {
            $this->logger->warning('ddd.command.failed', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'messageId' => $envelope->metadata->id->value(),
                'messageType' => $envelope->message::class,
            ]);

            throw $e;
        }

        $this->logger->info('ddd.command.completed', [
            'messageId' => $envelope->metadata->id->value(),
            'messageType' => $envelope->message::class,
        ]);

        return $result;
    }
}
