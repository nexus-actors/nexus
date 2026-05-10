<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Authorization;

use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;

/**
 * @psalm-api
 *
 * Project-supplied authorization decider. Throws AccessDeniedException on
 * denial. The bus middleware converts the throw to Either::left for
 * tryDispatch() callers (since AccessDeniedException implements
 * TerminalFailure but NOT BusInvariantException — domain failure, not
 * boot misconfiguration).
 */
interface AuthorizationDecider
{
    /**
     * @throws AccessDeniedException
     */
    public function decide(string $policy, mixed $subject, AuthorizationContext $context): void;
}
