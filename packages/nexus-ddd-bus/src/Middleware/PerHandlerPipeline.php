<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Override;

/**
 * @psalm-api
 *
 * Routes per-handler pipeline lookup at runtime. The
 * `CanonicalPipelineAssembler` builds one `MiddlewarePipeline` per
 * registered handler at boot — applying the
 * `#[Authorize(before: 'validation')]` flip per handler (panel H4) and
 * splicing adopter-supplied middleware (panel H13). This wrapper looks
 * up the correct pipeline by envelope message class on dispatch.
 *
 * Envelopes carrying a message class with no registered handler fall
 * through to the `$fallback` pipeline. The fallback is canonical (no
 * splices, default stage order); the upstream `HandlerInvocation`
 * middleware throws `HandlerNotFoundException` for unknown messages.
 */
final readonly class PerHandlerPipeline implements EnvelopePipeline
{
    /** @param array<class-string, MiddlewarePipeline> $perHandler */
    public function __construct(private array $perHandler, private MiddlewarePipeline $fallback) {}

    #[Override]
    public function dispatch(Envelope $envelope): mixed
    {
        $messageClass = $envelope->message::class;
        $pipeline = $this->perHandler[$messageClass] ?? $this->fallback;

        return $pipeline->dispatch($envelope);
    }
}
