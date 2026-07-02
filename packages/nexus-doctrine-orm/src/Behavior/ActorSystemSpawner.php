<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Override;

/**
 * Wires a Nexus ActorSystem into the ActorSpawner interface used by EntityRefFactory.
 *
 * @psalm-api
 */
final readonly class ActorSystemSpawner implements ActorSpawner
{
    public function __construct(private ActorSystem $system) {}

    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        return $this->system->spawn($props, $name);
    }
}
