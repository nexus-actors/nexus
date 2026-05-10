<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an event-handler method as in-tx. Validated at boot by
 * InProcessSameDbBootValidator (Phase 12a).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class InProcess {}
