<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\ClusterTcp\Message;

use Monadial\Nexus\Serialization\MessageType;

/**
 * Sent by the client node to the greeter actor on the remote node.
 * Travels over the TCP mesh as a MessagePack-serialised frame.
 */
#[MessageType('example.greet')]
final readonly class Greet
{
    public function __construct(public string $name) {}
}
