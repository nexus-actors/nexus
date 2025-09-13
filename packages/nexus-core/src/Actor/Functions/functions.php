<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Functions;

use Closure;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;

/**
 * @psalm-api
 *
 * Pipe-friendly function: applies mailbox config to Props.
 *
 * Usage with pipe operator:
 *   $behavior |> Props::fromBehavior(...) |> withMailbox($config)
 *
 * @return \Closure(Props<object>): Props<object>
 */
function withMailbox(MailboxConfig $config): Closure
{
    return static fn (Props $props): Props => $props->withMailbox($config);
}

/**
 * @psalm-api
 *
 * Pipe-friendly function: applies supervision strategy to Props.
 *
 * Usage with pipe operator:
 *   $behavior |> Props::fromBehavior(...) |> withSupervision($strategy)
 *
 * @return \Closure(Props<object>): Props<object>
 */
function withSupervision(SupervisionStrategy $strategy): Closure
{
    return static fn (Props $props): Props => $props->withSupervision($strategy);
}
