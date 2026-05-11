<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Fp\Functional\Option\Option;
use Override;

/**
 * @psalm-api
 *
 * Anonymous-principal default that always returns `Option::none()`. Use
 * in unit tests and for handlers whose authorization decisions don't
 * depend on the calling principal (e.g. system-internal commands).
 * Production deployments supply a real `PrincipalProvider` backed by
 * the host auth system.
 */
final class NoPrincipalProvider implements PrincipalProvider
{
    /** @return Option<Principal> */
    #[Override]
    public function current(): Option
    {
        return Option::none();
    }
}
