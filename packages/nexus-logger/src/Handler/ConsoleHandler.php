<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Handler;

use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Handler;
use Monadial\Nexus\Logger\Record;
use Override;

use function fwrite;

/**
 * @psalm-api
 *
 * Writes formatted records to a stream resource (typically STDOUT / STDERR).
 * The stream is NOT closed on destruct — caller owns its lifecycle.
 */
final class ConsoleHandler implements Handler
{
    /**
     * @param resource $stream
     */
    public function __construct(private $stream, private readonly Formatter $formatter) {}

    #[Override]
    public function handle(Record $record): void
    {
        fwrite($this->stream, $this->formatter->format($record) . "\n");
    }
}
