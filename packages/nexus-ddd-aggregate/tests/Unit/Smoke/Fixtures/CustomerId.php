<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;

/**
 * @psalm-immutable
 *
 * Smoke-test customer identifier. Carried inside {@see OrderPlaced} to
 * exercise nested-value-object replay during the smoke pipeline.
 */
final readonly class CustomerId extends UlidValue {}
