<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Async\Future;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use NoDiscard;

/**
 * @psalm-api
 *
 * @template T of object
 */
interface ActorRef
{
    /** @param T $message */
    public function tell(object $message): void;

    /**
     * Send a message and get a Future for the reply.
     *
     * The message is sent immediately (eager). The reply is received
     * via a lightweight FutureSlot. The handler replies with ctx->reply().
     *
     * @template R of object
     * @param T $message
     * @return Future<R>
     * @throws AskTimeoutException
     */
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future;

    public function path(): ActorPath;

    public function isAlive(): bool;
}
