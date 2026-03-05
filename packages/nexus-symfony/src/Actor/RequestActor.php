<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use Override;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ephemeral actor that handles exactly one HTTP request then stops.
 *
 * Spawned by WorkerSupervisorActor for each incoming HandleHttpRequest.
 * PostStop calls ResetInterface::reset() to clean up stateful Symfony services
 * (e.g. EntityManager, doctrine connection state) after the request.
 *
 * @extends AbstractActor<object>
 */
final class RequestActor extends AbstractActor
{
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

        $symfonyResponse = $this->kernel->handle($message->request);

        $message->responseChannel->push($symfonyResponse, 30.0);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($message->request, $symfonyResponse);
        }

        return Behavior::stopped();
    }

    #[Override]
    public function onPostStop(ActorContext $_ctx): void
    {
        $this->resetter?->reset();
    }
}
