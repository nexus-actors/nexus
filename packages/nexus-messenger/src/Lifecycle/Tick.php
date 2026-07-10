<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Monadial\Nexus\Core\Actor\UntracedMessage;

/**
 * Internal self-tick message driving LifecycleWatchdog threshold checks.
 *
 * @psalm-api
 */
final readonly class Tick implements UntracedMessage {}
