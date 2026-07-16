<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Throwable;

use function fwrite;

use const STDERR;

/**
 * Last-resort failure sink for the LogActor.
 *
 * When a Handler itself throws, the failure is written synchronously to
 * STDERR — losing a diagnostic line is preferable to crashing the actor
 * and dropping every record sitting behind it in the mailbox. The write
 * lives outside the actor class on purpose: it is the same deliberate,
 * bounded blocking I/O the Handler implementations perform, not work
 * that belongs on the actor's message-processing path.
 *
 * @internal
 */
final class HandlerFailureReporter
{
    public static function report(Throwable $failure): void
    {
        fwrite(STDERR, "nexus-logger handler failed: {$failure->getMessage()}\n");
    }
}
