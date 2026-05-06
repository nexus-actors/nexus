<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * Domain entity contract: identity-based equality. Both runtime type AND id must match.
 */
interface Entity extends Identifiable
{
    public function equals(Entity $other): bool;
}
