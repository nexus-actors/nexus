<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

use function strlen;
use function substr;

/**
 * @psalm-api
 *
 * Symmetric exit for `LoggingStartMiddleware`. Emits a single INFO record
 * on success (`ddd.command.completed`) or WARNING on failure
 * (`ddd.command.failed`) carrying the same envelope identifiers as the
 * start log so log scraping can correlate the dispatch lifecycle.
 *
 * The exception message is truncated at 1024 bytes to prevent unbounded
 * user-data from polluting logs (panel Security F5).
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class LoggingEndMiddleware implements Middleware
{
    public const int EXCEPTION_MESSAGE_MAX_LENGTH = 1024;

    public function __construct(private readonly LoggerInterface $logger) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        try {
            $result = $next($envelope);
        } catch (Throwable $e) {
            $this->logger->warning('ddd.command.failed', [
                'exception_class' => $e::class,
                'exception_message' => self::truncate($e->getMessage()),
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

    private static function truncate(string $message): string
    {
        if (strlen($message) <= self::EXCEPTION_MESSAGE_MAX_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::EXCEPTION_MESSAGE_MAX_LENGTH) . '...';
    }
}
