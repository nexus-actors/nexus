<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Attribute;

use Attribute;
use Monadial\Nexus\Core\Supervision\StrategyType;

#[Attribute(Attribute::TARGET_CLASS)]
final class Actorize
{
    public function __construct(
        public readonly bool $async = true,
        public readonly StrategyType $supervision = StrategyType::OneForOne,
        public readonly int $timeout = 5,
        public readonly ?bool $reset = null,
        public readonly ?string $namespace = null,
    ) {}
}
