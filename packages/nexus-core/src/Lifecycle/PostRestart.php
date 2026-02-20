<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class PostRestart implements Signal
{
    public function __construct(public Throwable $cause)
    {
    }
}
