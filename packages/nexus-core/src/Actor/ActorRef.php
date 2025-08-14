<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;

/**
 * @template T of object
 */
interface ActorRef
{
    /** @param T $message */
    public function tell(object $message): void;

    /**
     * @template R of object
     * @param callable(ActorRef<R>): T $messageFactory
     * @return R
     * @throws AskTimeoutException
     */
    #[\NoDiscard]
    public function ask(callable $messageFactory, Duration $timeout): object;

    public function path(): ActorPath;
    public function isAlive(): bool;
}
