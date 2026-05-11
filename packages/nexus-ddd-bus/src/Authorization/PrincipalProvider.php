<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Fp\Functional\Option\Option;

/**
 * @psalm-api
 *
 * Slot interface for adopter-supplied principal resolution. Bus
 * middleware reads `current()` once per dispatch and threads the value
 * into `AuthorizationContext`. Adopters back this with whatever auth
 * lives in the host (Symfony Security `TokenStorageInterface`, a JWT
 * decoder, RequestStack-style context, etc.). Per panel Security F2 —
 * principal must be an explicit slot rather than a hard-coded `None`.
 */
interface PrincipalProvider
{
    /** @return Option<Principal> */
    public function current(): Option;
}
