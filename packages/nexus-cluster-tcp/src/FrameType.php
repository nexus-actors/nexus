<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

/**
 * @psalm-api
 *
 * Identifies the purpose of a cluster TCP frame.
 * The backing integer is written as a single byte on the wire.
 */
enum FrameType: int
{
    case Error = 8;
    case Gossip = 4;
    case Handshake = 1;
    case HandshakeAck = 2;
    case Leave = 7;
    case Message = 3;
    case Ping = 5;
    case Pong = 6;
}
