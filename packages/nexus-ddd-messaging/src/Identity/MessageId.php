<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Framework-internal identifier for messages on the bus. Uniformly
 * ULID-backed (sortable + globally unique without a coordinator).
 * Distinct from domain identifiers — domain code creates
 * `OrderId extends UlidValue`; the framework creates `MessageId`.
 */
final readonly class MessageId extends UlidValue
{
    public static function generate(): self
    {
        return new self(new Ulid()->toBase32());
    }
}
