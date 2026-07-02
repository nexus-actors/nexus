<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Handler;

use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Record;
use Override;

use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;

use const LOCK_EX;
use const LOCK_UN;

/**
 * @psalm-api
 *
 * Append-mode file handler. Each handle() call opens, locks with flock(EX),
 * writes one line, flushes, unlocks, and closes. This is safe across
 * multiple processes (Swoole worker mode) and threads (Swoole 6 thread
 * mode) at the cost of an open/close per record.
 *
 * For very high write rates, prefer one handler per writer or use a
 * different transport (syslog, fluent-bit, etc) — file logging with
 * per-write fopen is fine for typical app traffic and standard practice
 * for monolog-style file sinks.
 */
final class FileHandler implements Handler
{
    public function __construct(private readonly string $path, private readonly Formatter $formatter) {}

    #[Override]
    public function handle(Record $record): void
    {
        $line = $this->formatter->format($record) . "\n";
        $fp = fopen($this->path, 'ab');

        if ($fp === false) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
    }
}
