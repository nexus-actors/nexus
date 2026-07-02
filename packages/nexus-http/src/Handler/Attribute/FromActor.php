<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Inject an actor reference registered with HttpApp. Works on constructor
 * parameters (constructor injection) and on handler/middleware invocation
 * method parameters.
 *
 * Constructor params may reference PoolSingleton or WorkerLocal actors only.
 * Method params may reference any mode including PerRequest.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromActor
{
    public function __construct(public string $name) {}
}
