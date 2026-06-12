<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use RuntimeException;

/**
 * Test double for ActorRef that records tell() invocations.
 *
 * @template-implements ActorRef<object>
 */
final class FakeActorRef implements ActorRef
{
    /** @var list<object> */
    public array $told = [];

    public function tell(object $message): void
    {
        $this->told[] = $message;
    }

    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('not implemented in fake');
    }

    public function path(): ActorPath
    {
        return ActorPath::root()->child('user')->child('fake');
    }

    public function isAlive(): bool
    {
        return true;
    }
}
