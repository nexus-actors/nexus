<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;

/**
 * Null-object ActorRef that captures dead letters.
 *
 * Messages sent via tell() are captured in an internal list for inspection.
 * ask() immediately throws AskTimeoutException.
 * isAlive() always returns false.
 *
 * @implements ActorRef<object>
 */
final class DeadLetterRef implements ActorRef
{
    private ActorPath $path;

    /** @var list<object> */
    private array $captured = [];

    public function __construct()
    {
        $this->path = ActorPath::fromString('/system/deadLetters');
    }

    public function tell(object $message): void
    {
        $this->captured[] = $message;
    }

    /**
     * @template R of object
     * @param callable(ActorRef<R>): object $messageFactory
     * @return R
     * @throws AskTimeoutException
     */
    #[\NoDiscard]
    public function ask(callable $messageFactory, Duration $timeout): object
    {
        throw new AskTimeoutException($this->path, $timeout);
    }

    public function path(): ActorPath
    {
        return $this->path;
    }

    public function isAlive(): bool
    {
        return false;
    }

    /** @return list<object> */
    public function captured(): array
    {
        return $this->captured;
    }
}
