<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Processor;

use Monadial\Nexus\Logger\Record;
use Monadial\Nexus\Logger\RecordProcessor;
use Override;

use function array_slice;
use function debug_backtrace;
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
final class CallerInfoProcessor implements RecordProcessor
{
    private const array SKIP_PREFIXES = [
        'Monadial\\Nexus\\Logger\\Logger',
        'Monadial\\Nexus\\Logger\\NexusLogger',
        'Monadial\\Nexus\\Logger\\Processor\\',
        'Psr\\Log\\',
    ];

    #[Override]
    public function process(Record $record): Record
    {
        /** @var list<array{class?: string, function?: string, file?: string, line?: int}> $trace */
        $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12), 1);

        foreach ($trace as $frame) {
            if (self::isInfra($frame)) {
                continue;
            }

            $extra = [];

            if (isset($frame['class'])) {
                $extra['class'] = $frame['class'];
            }

            if (isset($frame['function'])) {
                $extra['function'] = $frame['function'];
            }

            if (isset($frame['file'])) {
                $extra['file'] = $frame['file'];
            }

            if (isset($frame['line'])) {
                $extra['line'] = $frame['line'];
            }

            return $record->withExtra($extra);
        }

        return $record;
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
