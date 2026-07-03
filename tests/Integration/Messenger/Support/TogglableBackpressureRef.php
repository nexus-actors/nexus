<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Override;
use RuntimeException;

/**
 * @template-implements ActorRef<object>
 * @template-implements BackpressureCapable<object>
 */
final class TogglableBackpressureRef implements ActorRef, BackpressureCapable
{
    public EnqueueResult $result = EnqueueResult::Backpressured;

    /** @var list<object> */
    public array $accepted = [];

    #[Override]
    public function offer(object $message): EnqueueResult
    {
        if ($this->result === EnqueueResult::Accepted) {
            $this->accepted[] = $message;
        }

        return $this->result;
    }

    #[Override]
    public function tell(object $message): void
    {
        $this->accepted[] = $message;
    }

    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('not used in this test');
    }

    #[Override]
    public function path(): ActorPath
    {
        return ActorPath::root()->child('togglable');
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }
}
