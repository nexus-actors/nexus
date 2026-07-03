<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Override;

/**
 * Wraps a closure so actor refs can be spawned on the ActorSystem that
 * ConsumeCommand boots, rather than before the system exists.
 *
 * @psalm-api
 */
final readonly class CallbackConsumerSetup implements ConsumerSetup
{
    /** @param Closure(ActorSystem): MessageRouter $factory */
    public function __construct(private Closure $factory) {}

    #[Override]
    public function setup(ActorSystem $system): MessageRouter
    {
        return ($this->factory)($system);
    }
}
