<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Processor;

use Monadial\Nexus\Logger\Level;
use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Logger\RecordProcessor;
use Override;

use function array_slice;
use function array_values;
use function count;
use function debug_backtrace;
use function in_array;
use function str_starts_with;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * @psalm-api
 *
 * Captures the call site (class, function, file, line) at log-time and
 * writes it into Record::$extra. Must run synchronously on the caller's
 * thread — debug_backtrace() yields the actor's stack once the record
 * is dequeued.
 *
 * The processor walks the backtrace and skips frames belonging to PSR-3
 * scaffolding (Psr\Log, Monadial\Nexus\Logger\Logger,
 * Monadial\Nexus\Logger\NexusLogger, this processor itself) so the first
 * application frame is what lands in extra. This is more robust than a
 * fixed depth across info() / debug() / log() / processor chains.
 *
 * Output keys (all optional; only populated when present in the frame):
 *   class, function, file, line
 *
 * Pair with a Monolog LineFormatter template like:
 *   "%channel%.%level_name% %extra.class%::%extra.function%:%extra.line% — %message%"
 */
final readonly class CallerInfoProcessor implements RecordProcessor
{
    private const array SKIP_PREFIXES = [
        'Monadial\\Nexus\\Logger\\Logger',
        'Monadial\\Nexus\\Logger\\NexusLogger',
        'Monadial\\Nexus\\Logger\\Processor\\',
        'Psr\\Log\\',
    ];

    /**
     * @param list<Level>|null $levels  null = run on every record.
     *   Pass a list to only walk the backtrace for matching levels —
     *   useful when you want call-site info on debug/error/critical
     *   but want to skip the debug_backtrace() cost on high-volume
     *   info-level messages.
     */
    public function __construct(private ?array $levels = null) {}

    /**
     * Restrict the processor to the given levels. Equivalent to
     * `new CallerInfoProcessor([Level::Debug, Level::Error])`.
     */
    public static function onlyFor(Level ...$levels): self
    {
        return new self(array_values($levels));
    }

    #[Override]
    public function process(Record $record): Record
    {
        if ($this->levels !== null && !in_array($record->level, $this->levels, true)) {
            return $record;
        }

        /**
         * debug_backtrace semantics:
         *   - frame[i].function = the function executing at frame i
         *   - frame[i].file/line = where frame i was CALLED FROM (the caller)
         *
         * So when we find the first user frame[i] (the route closure), its
         * file/line point to the dispatcher that invoked it, not the
         * $logger->info() call site. The call site lives in frame[i-1]
         * (the last infra frame, AbstractLogger::info). Mirror Monolog's
         * IntrospectionProcessor: function/class from i, file/line from i-1.
         *
         * @var list<array{class?: string, function?: string, file?: string, line?: int}> $trace
         */
        $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12), 1);

        $i = 0;

        while ($i < count($trace) && self::isInfra($trace[$i])) {
            $i++;
        }

        if ($i >= count($trace)) {
            return $record;
        }

        $userFrame = $trace[$i];
        $callerFrame = $i > 0
            ? $trace[$i - 1]
            : $userFrame;

        $extra = [];

        if (isset($userFrame['class'])) {
            $extra['class'] = $userFrame['class'];
        }

        if (isset($userFrame['function'])) {
            $extra['function'] = $userFrame['function'];
        }

        if (isset($callerFrame['file'])) {
            $extra['file'] = $callerFrame['file'];
        }

        if (isset($callerFrame['line'])) {
            $extra['line'] = $callerFrame['line'];
        }

        return $record->withExtra($extra);
    }

    /**
     * @param array{class?: string, function?: string, file?: string, line?: int} $frame
     */
    private static function isInfra(array $frame): bool
    {
        $class = $frame['class'] ?? '';

        if ($class === '') {
            return false;
        }

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
