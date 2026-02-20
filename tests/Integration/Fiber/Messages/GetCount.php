<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber\Messages;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class GetCount
{
    /**
     * @param ActorRef<object> $replyTo
     */
    public function __construct(public ActorRef $replyTo) {}
}
