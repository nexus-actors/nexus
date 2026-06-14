<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Monadial\Nexus\Core\Actor\ActorRef;
use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @psalm-api
 *
 * PSR-3 LoggerInterface that forwards records to a LogActor via tell().
 * Calls return as soon as the message is enqueued — no formatting or I/O
 * happens on the caller's thread.
 *
 * minLevel is checked before constructing the Record so below-threshold
 * calls cost only a single int comparison.
 */
final class Logger extends AbstractLogger
{
    /**
     * @param ActorRef<Record> $sink
     */
    public function __construct(
        private readonly ActorRef $sink,
        private readonly string $channel,
        private readonly Level $minLevel = Level::Debug,
    ) {}

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $resolved = Level::fromPsr3((string) $level);

        if (!$resolved->isAtLeast($this->minLevel)) {
            return;
        }

        /** @var array<string, mixed> $context */
        $record = Record::create($resolved, $message, $context, $this->channel);
        $this->sink->tell($record);
    }

    /**
     * Returns a new Logger bound to a different channel name, sharing the
     * underlying sink and level. Useful for per-subsystem tagging:
     *
     *   $appLogger = $logger;
     *   $httpLogger = $logger->withChannel('http');
     */
    public function withChannel(string $channel): self
    {
        return new self($this->sink, $channel, $this->minLevel);
    }

    public function withMinLevel(Level $level): self
    {
        return new self($this->sink, $this->channel, $level);
    }
}
