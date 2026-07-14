<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\ClusterTcp\Message;

use Monadial\Nexus\Serialization\MessageType;

/**
 * Reply sent by the greeter actor back to the asking node.
 * Travels over the TCP mesh as a MessagePack-serialised reply frame.
 */
#[MessageType('example.greeted')]
final readonly class Greeted
{
    public function __construct(public string $message) {}
}
