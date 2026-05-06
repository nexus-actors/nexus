<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Backoff;

use Fp\Functional\Option\Option;
use Throwable;

/**
 * @psalm-api
 *
 * No-retry strategy: every failure surfaces immediately, no backoff.
 */
final class NoRetry implements BackoffStrategy
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    #[\Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::none();
    }
}
