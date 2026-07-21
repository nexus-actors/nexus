<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;
use Monadial\Nexus\Http\Security\AuthorizationRequirement;

use function array_values;

/**
 * @psalm-api
 *
 * All-of role check. 403 if the Principal lacks ANY of the listed roles.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresRole implements AuthorizationRequirement
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
