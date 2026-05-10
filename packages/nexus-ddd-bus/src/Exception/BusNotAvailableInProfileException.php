<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Bus\Profile\Profile;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a bus name resolves to an implementation forbidden by the
 * active profile (e.g., async/actor bus requested under `Profile::Sync`).
 */
final class BusNotAvailableInProfileException extends BusBootException
{
    public static function for(string $busName, Profile $profile): self
    {
        return new self(sprintf('Bus `%s` is not available in profile `%s`.', $busName, $profile->value));
    }
}
