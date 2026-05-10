<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Validate
{
    /** @param list<string> $groups */
    public function __construct(public array $groups = []) {}
}
