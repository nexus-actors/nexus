<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class ChildFailed implements Signal
{
    /**
     * @param ActorRef<object> $child
     */
    public function __construct(
        public ActorRef $child,
        public \Throwable $cause,
    ) {}
}
