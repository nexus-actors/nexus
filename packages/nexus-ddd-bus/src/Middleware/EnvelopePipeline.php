<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;

/**
 * @psalm-api
 *
 * Sealed dispatcher contract consumed by `Sync*Bus` impls. Two
 * implementations exist: `MiddlewarePipeline` (a single canonical stack;
 * test harnesses and degenerate single-handler buses) and
 * `PerHandlerPipeline` (the production case where the canonical stack
 * is materialized per handler so the per-handler
 * `#[Authorize(before: 'validation')]` flip is baked at boot, panel H4).
 */
interface EnvelopePipeline
{
    /** @param Envelope<object> $envelope */
    public function dispatch(Envelope $envelope): mixed;
}
