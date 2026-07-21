<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;
use Monadial\Nexus\Http\Security\AuthorizationRequirement;

use function array_values;

/**
 * @psalm-api
 *
 * All-of scope check. 403 if the Principal lacks ANY of the listed scopes.
 *
 *   #[RequiresScope('orders.read', 'orders.write')]
 *   final class CreateOrderHandler { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresScope implements AuthorizationRequirement
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
