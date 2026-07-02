<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Inject a service from the PSR-11 container.
 *
 *   #[FromService('logger.audit')] LoggerInterface $log    // resolve by container id
 *   #[FromService] MyService $service                       // resolve by type
 *
 * Works on constructor params and on handler/middleware invocation method
 * params. Requires a ContainerInterface to be supplied to HttpApp::create().
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromService
{
    public function __construct(public ?string $id = null) {}
}
