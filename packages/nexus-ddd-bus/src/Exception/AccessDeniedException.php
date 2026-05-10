<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

use function get_debug_type;
use function is_scalar;
use function sprintf;

/**
 * @psalm-api
 *
 * Authorization rejection — a domain fact AND a terminal failure (no
 * retry will succeed). Extends `DomainException` because the rule
 * "principal X cannot do Y" is a business rule, not a framework wiring
 * fault. Implements `TerminalFailure` so the idempotency middleware
 * locks the reservation rather than releasing it for retry.
 */
final class AccessDeniedException extends DomainException implements TerminalFailure
{
    public static function for(string $policy, mixed $subject, ?Principal $principal = null): self
    {
        $subjectStr = is_scalar($subject)
            ? (string) $subject
            : get_debug_type($subject);
        $principalStr = $principal !== null
            ? sprintf(' (principal=%s)', $principal->id())
            : '';

        return new self(sprintf(
            'Access denied: principal cannot perform `%s` on `%s`%s.',
            $policy,
            $subjectStr,
            $principalStr,
        ));
    }
}
