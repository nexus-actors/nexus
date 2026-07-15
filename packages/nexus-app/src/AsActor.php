<?php

declare(strict_types=1);

namespace Monadial\Nexus\App;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an ActorHandler for auto-registration + auto-spawn under $name. The skeleton's
 * Kernel registers this attribute for symfony/di autoconfiguration; a compiler pass folds
 * every attributed handler into the ActorRegistry.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsActor
{
    public function __construct(public string $name) {}
}
