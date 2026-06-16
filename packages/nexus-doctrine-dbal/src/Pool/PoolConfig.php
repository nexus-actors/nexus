<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use InvalidArgumentException;
use Monadial\Nexus\Runtime\Duration;

/**
 * @psalm-api
 */
final readonly class PoolConfig
{
    public Duration $acquireTtl;
    public Duration $borrowTimeout;
    public Duration $idleTtl;

    public function __construct(
        ?Duration $acquireTtl = null,
        ?Duration $borrowTimeout = null,
        public bool $healthCheckOnBorrow = false,
        ?Duration $idleTtl = null,
        public int $max = 16,
        public int $minIdle = 2,
        public string $validationQuery = 'SELECT 1',
    ) {
        if ($max <= 0) {
            throw new InvalidArgumentException(sprintf('max (%d) must be positive', $max));
        }

        if ($minIdle > $max) {
            throw new InvalidArgumentException(sprintf('minIdle (%d) must not exceed max (%d)', $minIdle, $max));
        }

        $this->acquireTtl = $acquireTtl ?? Duration::seconds(30);
        $this->borrowTimeout = $borrowTimeout ?? Duration::seconds(5);
        $this->idleTtl = $idleTtl ?? Duration::seconds(300);
    }
}
