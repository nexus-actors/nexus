<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
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

    #[Override]
    public function tell(object $message): void
    {
        $this->captured[] = $message;
    }

    /**
     * @return Future<object>
     * @throws AskTimeoutException
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new AskTimeoutException($this->path, $timeout);
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
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
