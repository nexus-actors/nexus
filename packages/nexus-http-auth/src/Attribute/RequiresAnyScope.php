<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Attribute;

use Attribute;

use function array_values;

/**
 * @psalm-api
 *
 * Any-of scope check. 403 if the Principal lacks ALL of the listed scopes
 * (i.e. has none of them). Passes if at least one is present.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresAnyScope
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
