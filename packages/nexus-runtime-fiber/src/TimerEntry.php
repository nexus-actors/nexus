<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Core\Duration;

/**
 * @psalm-api
 *
 * @internal
 */
final readonly class TimerEntry
{
    /**
     * @param \Closure(): void $callback
     */
    public function __construct(
        public Closure $callback,
        public DateTimeImmutable $fireAt,
        public bool $repeating,
        public ?Duration $interval,
        public FiberCancellable $cancellable,
    ) {}
}
