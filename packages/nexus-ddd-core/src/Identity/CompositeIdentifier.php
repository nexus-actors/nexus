<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/**
 * @psalm-api
 *
 * Identifier composed of multiple named Identifier components
 * (e.g., (TenantId, OrderId)). Composes identity from identity — components
 * are themselves Identifier instances, not raw scalars. Storage uses canonical
 * string serialization (subclass-controlled via `fromString`); query layers
 * can also access components by name.
 */
interface CompositeIdentifier extends Identifier
{
    /** @return array<string, Identifier> components by name (in declaration order) */
    public function components(): array;
}
