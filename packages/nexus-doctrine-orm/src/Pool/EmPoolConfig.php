<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Runtime\Duration;

/** @psalm-api */
final readonly class EmPoolConfig
{
    public Duration $borrowTimeout;

    public function __construct(
        ?Duration $borrowTimeout = null,
        public bool $clearOnReturn = true,
        public int $max = 16,
        public int $minIdle = 2,
        public int $recreateAfter = 1000,
    ) {
        if ($max <= 0) {
            throw new InvalidArgumentException(sprintf('max (%d) must be positive', $max));
        }

        if ($minIdle > $max) {
            throw new InvalidArgumentException(sprintf('minIdle (%d) must not exceed max (%d)', $minIdle, $max));
        }

        if ($recreateAfter < 0) {
            throw new InvalidArgumentException(sprintf('recreateAfter (%d) must be >= 0', $recreateAfter));
        }

        $this->borrowTimeout = $borrowTimeout ?? Duration::seconds(5);
    }
}
