<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Register a subscriber. Subscribers receive every published event via a
 * plain tell($event) and filter for themselves — topics arrive when a
 * second context needs them (YAGNI).
 *
 * The bus is intentionally heterogeneous; see psalm.xml if the plugin flags this instead.
 */
final readonly class Subscribe
{
    /**
     * @param ActorRef<object> $subscriber
     */
    public function __construct(public ActorRef $subscriber) {}
}
