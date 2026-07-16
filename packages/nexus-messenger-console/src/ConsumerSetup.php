<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Routing\MessageRouter;

/**
 * Implement this interface to wire your handler actors after the ActorSystem boots.
 *
 * This is the recommended way to integrate with ConsumeCommand when actor refs
 * need to be created on the same system the command boots:
 *
 * ```php
 * $command = new ConsumeCommand(
 *     new FiberRuntime(),
 *     $transport,
 *     new CallbackConsumerSetup(static function (ActorSystem $system): MessageRouter {
 *         $ref = $system->spawn(Props::fromFactory(fn() => new OrderProcessor()), 'orders');
 *
 *         return new MapMessageRouter(Route::to(OrderPlaced::class, $ref));
 *     }),
 * );
 * ```
 *
 * A plain {@see MessageRouter} is also accepted when actor refs are already available at wiring time.
 *
 * @psalm-api
 */
interface ConsumerSetup
{
    /**
     * Called after the ActorSystem boots and before receivers spawn.
     * Spawn your handler actors on $system here and return the router
     * that targets them.
     */
    public function setup(ActorSystem $system): MessageRouter;
}
