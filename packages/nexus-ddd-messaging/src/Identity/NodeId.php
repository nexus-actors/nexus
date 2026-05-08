<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Identifies a logical node (PHP process, actor system, worker pool
 * member, DB replica) for vector-clock accounting. ULID-backed for the
 * same reasons as MessageId — sortable, globally unique, no coordination.
 */
final readonly class NodeId extends UlidValue
{
    public static function generate(): self
    {
        return new self(new Ulid()->toBase32());
    }
}
