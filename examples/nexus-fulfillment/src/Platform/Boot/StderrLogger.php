<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Psr\Log\AbstractLogger;
use Stringable;

use function date;
use function fwrite;
use function json_encode;
use function sprintf;

/**
 * Synchronous stderr logger for the pre-actor-system boot phase. Once the
 * ActorSystem is up, App switches to the async NexusLogger.
 */
final class StderrLogger extends AbstractLogger
{
    private function __construct(private readonly string $channel) {}

    public static function create(string $channel): self
    {
        return new self($channel);
    }

    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $suffix = $context === []
            ? ''
            : ' ' . (string) json_encode($context);

        /** @var resource $stderr */
        $stderr = STDERR;
        fwrite($stderr, sprintf(
            "[%s] %s.%s: %s%s\n",
            date('c'),
            $this->channel,
            (string) $level,
            (string) $message,
            $suffix,
        ));
    }
}
