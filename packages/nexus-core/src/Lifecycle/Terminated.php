<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Lifecycle;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class Terminated implements Signal
{
    /**
     * @param ActorRef<object> $ref
     */
    public function __construct(public ActorRef $ref)
    {
    }
}
