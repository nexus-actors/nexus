<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Runtime\Exception\FutureException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;

/**
 * @psalm-api
 *
 * Fails an ask future immediately when the target mailbox refuses the
 * message (dropped or backpressured). Distinct from AskTimeoutException:
 * the message never entered the mailbox, so waiting out the timeout would
 * only delay the inevitable.
 */
final class AskUndeliverableException extends ActorException implements FutureException
{
    public function __construct(public readonly ActorPath $target, public readonly EnqueueResult $result)
    {
        parent::__construct("Ask to {$target} was {$result->value} by the mailbox");
    }
}
