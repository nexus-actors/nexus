<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Duration;

final readonly class GoodMessage {}

final class BadMessage {}

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

    /** @param ActorContext<GoodMessage> $ctx */
    public function scheduleReadonlyMessage(ActorContext $ctx): void
    {
        $ctx->scheduleOnce(Duration::millis(100), new GoodMessage());
    }

    /** @param ActorContext<BadMessage> $ctx */
    public function scheduleMutableMessage(ActorContext $ctx): void
    {
        $ctx->scheduleOnce(Duration::millis(100), new BadMessage());
        $ctx->scheduleRepeatedly(Duration::millis(100), Duration::millis(500), new BadMessage());
    }
}
