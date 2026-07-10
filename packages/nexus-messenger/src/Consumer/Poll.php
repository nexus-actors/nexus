<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * Internal self-tick message driving the ReceiverActor poll loop.
 *
 * @psalm-api
 */
final readonly class Poll implements UntracedMessage {}
