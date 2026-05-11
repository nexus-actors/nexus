<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Support;

use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Bus\Sleep\SleepStrategy;
use Override;

/**
 * Test fixture: a `SleepStrategy` that captures each call instead of
 * sleeping. Tests assert against `$calls` to verify the OCC retry loop
 * requested the expected backoff durations without making the suite
 * actually wait.
 *
 * @psalm-api
 */
final class RecordingSleepStrategy implements SleepStrategy
{
    /** @var list<FiniteDuration> */
    public array $calls = [];

    #[Override]
    public function sleep(FiniteDuration $duration): void
    {
        $this->calls[] = $duration;
    }
}
