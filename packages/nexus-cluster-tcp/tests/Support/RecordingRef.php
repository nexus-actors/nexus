<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use RuntimeException;

/**
 * Test double for `ActorRef` + `BackpressureCapable` that records `tell()`/`offer()`
 * invocations with a configurable `EnqueueResult`.
 *
 * @template-implements ActorRef<object>
 * @template-implements BackpressureCapable<object>
 */
final class RecordingRef implements ActorRef, BackpressureCapable
{
    public EnqueueResult $offerResult = EnqueueResult::Accepted;

    /** @var list<object> */
    public array $told = [];

    /** @var list<object> */
    public array $offered = [];

    public function tell(object $message): void
    {
        $this->told[] = $message;
    }

    public function offer(object $message): EnqueueResult
    {
        $this->offered[] = $message;

        return $this->offerResult;
    }

    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('not implemented in RecordingRef');
    }

    public function path(): ActorPath
    {
        return ActorPath::root()->child('user')->child('recording-ref');
    }

    public function isAlive(): bool
    {
        return true;
    }
}
