<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Monolog;

use DateTimeImmutable;
use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use Monolog\Formatter\FormatterInterface;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use Override;

use function intval;
use function is_string;
use function round;
use function rtrim;

/**
 * @psalm-api
 *
 * Drops any Monolog FormatterInterface into the nexus-logger pipeline.
 * Useful when you want one of Monolog's formatters (JsonFormatter,
 * LineFormatter, GelfMessageFormatter, LogstashFormatter, ElasticsearchFormatter,
 * etc.) but still want to ship the resulting string through the nexus
 * ConsoleHandler / FileHandler / ThreadQueueHandler.
 *
 * Soft-depends on monolog/monolog ^3.0; install separately when used.
 */
final readonly class MonologFormatterAdapter implements Formatter
{
    public function __construct(private FormatterInterface $delegate) {}

    /**
     * @psalm-suppress InvalidOperand, MixedAssignment
     */
    #[Override]
    public function format(Record $record): string
    {
        $seconds = (int) $record->timestamp;
        $millis = intval(round(($record->timestamp - $seconds) * 1000));
        $datetime = (new DateTimeImmutable('@' . $seconds))
            ->modify('+' . $millis . ' milliseconds');

        $logRecord = new LogRecord(
            datetime: $datetime,
            channel: $record->channel,
            level: self::mapLevel($record->level),
            message: $record->message,
            context: $record->context,
            extra: $record->extra,
        );

        $formatted = $this->delegate->format($logRecord);

        return is_string($formatted)
            ? rtrim($formatted, "\n")
            : (string) $formatted;
    }

    private static function mapLevel(Level $level): MonologLevel
    {
        return match ($level) {
            Level::Alert => MonologLevel::Alert,
            Level::Critical => MonologLevel::Critical,
            Level::Debug => MonologLevel::Debug,
            Level::Emergency => MonologLevel::Emergency,
            Level::Error => MonologLevel::Error,
            Level::Info => MonologLevel::Info,
            Level::Notice => MonologLevel::Notice,
            Level::Warning => MonologLevel::Warning,
        };
    }
}
