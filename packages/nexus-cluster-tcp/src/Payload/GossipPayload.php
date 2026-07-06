<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\MessageType;

/**
 * @psalm-api
 *
 * Periodic gossip message exchanged between peers to converge cluster view.
 * `view` maps node path-prefix → advertise endpoint for every known live node.
 * `registrations` is reserved for C2 (service discovery); always empty in C1.
 *
 * @psalm-type RegistrationMap = array<string, string>
 */
#[MessageType('cluster.gossip')]
final readonly class GossipPayload
{
    /**
     * @param array<string, string>        $view          Node path-prefix → host:port.
     * @param list<array<string, string>>  $registrations Service registrations (C2); empty in C1.
     */
    public function __construct(public array $view, public array $registrations) {}
}
