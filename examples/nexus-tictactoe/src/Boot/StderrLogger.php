<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

use Monadial\Nexus\Logger\Formatter\LineFormatter;
use Monadial\Nexus\Logger\Handler\ConsoleHandler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Mdc;
use Monadial\Nexus\Logger\Record;
use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

use const STDERR;

/**
 * Synchronous PSR-3 logger for the pre-actor phase of boot. Reuses the same
 * {@see ConsoleHandler} + {@see LineFormatter} the async NexusLogger uses in
 * the worker phase, so the two loggers produce identical output. No Monolog
 * dependency, no ActorSystem needed.
 */
final class StderrLogger extends AbstractLogger
{
    private function __construct(private readonly ConsoleHandler $handler, private readonly string $channel) {}

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        // PSR-3 declares array-key keys; context is string-keyed by convention.
        // Same boundary redeclaration nexus-logger's own Logger::log() uses.
        /** @var array<string, mixed> $context */
        $record = Record::create(
            Level::fromPsr3((string) $level),
            $message,
            $context,
            $this->channel,
            Mdc::getAll(),
        );

        $this->handler->handle($record);
    }

    public static function create(string $channel): LoggerInterface
    {
        return new self(new ConsoleHandler(STDERR, new LineFormatter()), $channel);
    }
}
