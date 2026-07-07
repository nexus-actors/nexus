<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\ClusterTcp\Message;

/**
 * Internal timer message — never serialised over the wire.
 * Sent to the client actor by its own scheduleRepeatedly timer.
 */
final readonly class ClientTick {}
