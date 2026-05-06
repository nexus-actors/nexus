<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

/**
 * @psalm-api
 *
 * Identifier composed of multiple named components (e.g., (tenantId, orderId)).
 * Storage uses canonical string serialization; query layers can also access components.
 */
interface CompositeIdentifier extends Identifier
{
    /** @return array<string, scalar> components by name (in declaration order) */
    public function components(): array;
}
