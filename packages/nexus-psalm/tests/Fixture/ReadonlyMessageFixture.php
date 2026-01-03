<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorRef;

final readonly class GoodMessage {
}

final class BadMessage {
}

final class ReadonlyMessageFixture
{
    /** @param ActorRef<GoodMessage> $ref */
    public function tellWithReadonlyMessage(ActorRef $ref): void
    {
        $ref->tell(new GoodMessage());
    }

    /** @param ActorRef<BadMessage> $ref */
    public function tellWithMutableMessage(ActorRef $ref): void
    {
        $ref->tell(new BadMessage());
    }
}
