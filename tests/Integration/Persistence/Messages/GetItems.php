<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence\Messages;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class GetItems
{
    /**
     * @param ActorRef<object> $replyTo
     */
    public function __construct(public ActorRef $replyTo) {}
}
