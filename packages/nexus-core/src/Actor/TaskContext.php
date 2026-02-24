<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Override;
use Psr\Log\LoggerInterface;

/**
 * @psalm-api
 *
 * Context passed to background tasks spawned via {@see ActorContext::spawnTask()}.
 *
 * Provides cooperative cancellation and a channel back to the parent actor.
 */
final class TaskContext implements Cancellable
{
    private bool $cancelled = false;

    /**
     * @param ActorRef<object> $parentRef
     */
    public function __construct(private readonly ActorRef $parentRef, private readonly LoggerInterface $logger) {}

    /**
     * Send a message to the parent actor.
     */
    public function tell(object $message): void
    {
        $this->parentRef->tell($message);
    }

    #[Override]
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function log(): LoggerInterface
    {
        return $this->logger;
    }
}
