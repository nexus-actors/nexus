<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

/**
 * @psalm-api
 *
 * Frozen registration record. Consumed by ResolvedActorTable at compile time.
 */
final readonly class ActorRegistrationEntry
{
    public function __construct(
        public string $name,
        public Props $props,
        public ActorMode $mode,
        public ?SupervisionStrategy $supervision,
        public ?MailboxConfig $mailbox,
    ) {}
}
