<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Splice record for a single adopter / package-supplied Middleware.
 * Carried in `BusBuildResult::$customMiddlewares` in registration order;
 * Phase 13's `Sync*Bus` constructors walk the list and splice each entry
 * into the canonical pipeline at the position named by `$before` (or
 * append after the last canonical stage when `$before === null`).
 *
 * The `?PipelineStage $before` is a documented narrow exception to the
 * no-null rule: PHP attribute parameter defaults cannot be `Option::none()`.
 */
final readonly class CustomMiddlewareRegistration
{
    public function __construct(public Middleware $middleware, public ?PipelineStage $before) {}
}
