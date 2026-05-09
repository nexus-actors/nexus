<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;

/**
 * @psalm-immutable
 *
 * Smoke-test identifier for the {@see Order} aggregate fixture. ULID-backed
 * so smoke tests can mint fresh ids without relying on a generator.
 */
final readonly class OrderId extends UlidValue {}
