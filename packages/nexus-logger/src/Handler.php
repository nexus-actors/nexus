<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

/**
 * @psalm-api
 *
 * Terminal sink for log records. The actor invokes handle() in turn for each
 * registered handler; per-handler exceptions are caught upstream so one
 * failing handler cannot starve the others.
 */
interface Handler
{
    public function handle(Record $record): void;
}
