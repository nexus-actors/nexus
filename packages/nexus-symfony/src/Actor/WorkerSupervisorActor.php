<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use Override;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Long-lived supervisor actor per HTTP worker.
 *
 * Receives HandleHttpRequest messages from NexusRunner and spawns a fresh
 * RequestActor child for each request. The child handles the request, pushes
 * the response to the channel, then stops — triggering PostStop cleanup.
 *
 * Child naming: "request-{monotonic-counter}" ensures unique names per worker.
 * The counter is local to this actor instance (not static) for testability.
 *
 * @implements ActorHandler<object>
 */
final class WorkerSupervisorActor implements ActorHandler
{
    private int $requestSeq = 0;

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly ?ResetInterface $resetter,
    ) {}

    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof HandleHttpRequest) {
            return Behavior::unhandled();
        }

        $name = 'request-' . ++$this->requestSeq;

        $kernel   = $this->kernel;
        $resetter = $this->resetter;

        $ref = $ctx->spawn(
            Props::fromFactory(static fn() => new RequestActor($kernel, $resetter)),
            $name,
        );

        $ref->tell($message);

        return Behavior::same();
    }
}
