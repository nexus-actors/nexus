<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Synchronous Monolog → stderr logger used during bootstrap (before the
 * actor system exists) and as a fallback inside `WorkerStart` failures.
 *
 * After a worker has booted its ActorSystem, prefer the async NexusLogger
 * for hot-path logging — this one blocks the calling thread on each
 * record.
 */
final class StderrLogger
{
    public static function create(string $channel): LoggerInterface
    {
        $handler = new StreamHandler('php://stderr', Level::Debug);
        $handler->setFormatter(new LineFormatter(
            format: "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            dateFormat: 'Y-m-d H:i:s.u',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        ));

        return (new Logger($channel))->pushHandler($handler);
    }
}
