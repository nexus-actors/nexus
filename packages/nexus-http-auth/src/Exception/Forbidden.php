<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * Principal present but lacks required scope / role / policy. Mapped to
 * 403 by AuthorizationMiddleware. `missing` lists the constraints that
 * failed — empty array means "Authorize policy returned false" (opaque).
 *
 * `missing` is included in the 403 JSON body for client debugging. It
 * NEVER contains the Principal's actual claims — that's information
 * disclosure.
 */
final class Forbidden extends AuthException
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing = [], string $message = 'Forbidden')
    {
        parent::__construct($message);
    }
}
