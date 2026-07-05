<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

/**
 * How the reply queue behind a ReplyChannel lives and dies.
 *
 * - DeleteOnShutdown: created per instance, best-effort teardown on close().
 * - Ephemeral: created per instance, left to the broker (TTL/auto-delete) to reap.
 * - Persistent: pre-provisioned shared queue, never created or torn down here.
 *
 * @psalm-api
 */
enum ReplyQueueLifecycle
{
    case DeleteOnShutdown;
    case Ephemeral;
    case Persistent;
}
