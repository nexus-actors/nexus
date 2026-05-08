<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Hint to bus implementations that this message should be delivered in
 * order with respect to other messages bearing the same `correlationKey`.
 * Bus impls MAY honor it (Symfony Messenger via partition key, actor-bus
 * via per-actor mailbox); they are not REQUIRED to. Consumers MUST NOT
 * assume ordering is enforced just because the stamp is present.
 */
final readonly class PerCorrelationKeyOrdered implements Stamp
{
    public function __construct(public string $correlationKey) {}
}
