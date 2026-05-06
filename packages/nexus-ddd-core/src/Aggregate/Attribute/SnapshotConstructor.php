<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks a static method on an aggregate (or sub-entity, or PM) as the constructor
 * to use during snapshot rehydration. Valinor calls it with snapshot-state fields.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class SnapshotConstructor {}
