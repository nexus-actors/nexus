<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Monolog;

use DateTimeImmutable;
use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use Monolog\Handler\HandlerInterface;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use Override;

use function round;

/**
 * @psalm-api
 *
 * Drops any Monolog HandlerInterface into the nexus-logger pipeline.
 *
 * Each nexus Record is converted to a Monolog LogRecord, then forwarded
 * to the wrapped handler. Because nexus-logger's actor invokes Handler::handle
 * on its own turn (not the caller's), the wrapped Monolog handler's I/O
 * cost stays off the request path.
 *
 * Usage:
 *
 *   use Monolog\Handler\RotatingFileHandler;
 *   use Monolog\Handler\SyslogHandler;
 *
 *   NexusLogger::create($system, 'app')
 *       ->handler(new MonologHandlerAdapter(new RotatingFileHandler('/var/log/app.log', 7)))
 *       ->handler(new MonologHandlerAdapter(new SyslogHandler('app')))
 *       ->build();
 *
 * Soft-depends on monolog/monolog ^3.0; install separately when used.
 */
final readonly class MonologHandlerAdapter implements Handler
{
    /**
     * @param list<callable(LogRecord): LogRecord> $processors
     *   Monolog processors applied to the converted LogRecord before the
     *   delegate handler runs. Use this to attach Monolog's stock
     *   processors (HostnameProcessor, ProcessIdProcessor, MemoryUsageProcessor,
     *   GitProcessor, etc.) — they would otherwise be skipped because
     *   we bypass Monolog\Logger. All Monolog processors implement
     *   ProcessorInterface (an __invoke contract), so they're callable.
     */
    public function __construct(private HandlerInterface $delegate, private array $processors = []) {}

    #[Override]
    public function handle(Record $record): void
    {
        $logRecord = $this->toMonologRecord($record);

        foreach ($this->processors as $processor) {
            $logRecord = $processor($logRecord);
        }

        $this->delegate->handle($logRecord);
    }

    /**
     * @psalm-suppress InvalidOperand
     */
    private function toMonologRecord(Record $record): LogRecord
    {
        $seconds = (int) $record->timestamp;
        $millis = (int) round(($record->timestamp - $seconds) * 1000);
        $datetime = (new DateTimeImmutable('@' . $seconds))
            ->modify('+' . $millis . ' milliseconds');

        return new LogRecord(
            datetime: $datetime,
            channel: $record->channel,
            level: self::mapLevel($record->level),
            message: $record->message,
            context: $record->context,
            extra: $record->extra,
        );
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
