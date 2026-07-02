<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

/**
 * @psalm-api
 *
 * Synchronous record decorator. Runs on the caller's thread inside
 * Logger::log() before the record is enqueued to the LogActor, so it
 * can capture call-site state (debug_backtrace, coroutine context,
 * MDC) that would be invisible to a Handler running on the actor's
 * turn.
 */
interface RecordProcessor
{
    public function process(Record $record): Record;
}
