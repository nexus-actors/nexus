<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;
use Monadial\Nexus\Http\Security\AuthorizationRequirement;

use function array_values;

/**
 * @psalm-api
 *
 * Any-of role check. Passes if the Principal has at least one of the listed roles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAnyRole implements AuthorizationRequirement
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
