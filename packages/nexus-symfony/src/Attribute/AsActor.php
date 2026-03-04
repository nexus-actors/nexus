<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsActor
{
    public function __construct(public readonly string $name) {}
}
