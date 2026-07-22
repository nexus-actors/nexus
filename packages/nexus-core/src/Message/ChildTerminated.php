<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

use Monadial\Nexus\Core\Actor\ActorPath;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @internal Internal system message: a child sends this to its parent when it
 * terminates so the parent prunes the child from its children map and the name
 * becomes reusable. Not for direct use.
 */
final readonly class ChildTerminated implements SystemMessage
{
    public function __construct(public ActorPath $childPath) {}
}
