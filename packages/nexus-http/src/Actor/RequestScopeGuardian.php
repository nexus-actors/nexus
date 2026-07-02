<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Actor that parents all per-request actor spawns. Stop-only supervision
 * means a crashing per-request actor stops without restart; the awaiting
 * handler observes the crash via MailboxClosedException.
 */
final class RequestScopeGuardian
{
    public const string ACTOR_NAME = '__nexus_http_request_scope_guardian__';

    /** @return Props<object> */
    public static function props(): Props
    {
        /**
         * @psalm-suppress UnusedClosureParam
         *
         * @var Closure(ActorContext<object>, object): Behavior<object> $handler
         */
        $handler = static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same();

        return Props::fromBehavior(Behavior::receive($handler));
    }
}
