<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture;

/**
 * Failure-reply message the runner delivers via `thenReplyOnFailure` when a
 * command's flush blows up. Carries the caught throwable's message so the
 * caller can assert it learned about the failure instead of hanging.
 */
final readonly class FlushFailed
{
    public function __construct(public string $reason) {}
}
