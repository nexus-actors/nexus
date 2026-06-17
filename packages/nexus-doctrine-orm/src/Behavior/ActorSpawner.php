<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;

/**
 * Minimal interface for spawning named actors.
 *
 * Allows EntityRefFactory to be tested without depending on the final ActorSystem class.
 *
 * @psalm-api
 */
interface ActorSpawner
{
    public function spawn(Props $props, string $name): ActorRef;
}
