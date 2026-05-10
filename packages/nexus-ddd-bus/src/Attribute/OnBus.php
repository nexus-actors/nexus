<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Routing hint per umbrella spec §8.2. Resolution order: explicit DSL →
 * #[OnBus(name:)] → namespace-pattern → default.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OnBus
{
    public function __construct(public string $name) {}
}
