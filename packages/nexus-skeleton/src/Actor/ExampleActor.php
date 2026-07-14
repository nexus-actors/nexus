<?php

declare(strict_types=1);

namespace App\Actor;

use App\Message\Ping;
use App\Message\Pong;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

final class ExampleActor
{
    public static function behavior(): Behavior
    {
        return Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof Ping) {
                    $ctx->log()->info('ping received');
                    $msg->replyTo->tell(new Pong());
                }

                return Behavior::same();
            },
        );
    }
}
