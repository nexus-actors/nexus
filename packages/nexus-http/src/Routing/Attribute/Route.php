<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Declare an HTTP route on an action class. Repeatable: one class may
 * declare multiple endpoints.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @param list<string> $middleware */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $name = null,
        public array $middleware = [],
    ) {}
}
