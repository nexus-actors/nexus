<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool;

use Closure;
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelDispatch;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelReady;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelResponse;
use Override;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Owns the full lifecycle of a single Symfony kernel instance.
 *
 * Boot: onPreStart() boots the kernel (and wires the services resetter).
 * Request: handle() processes one KernelDispatch, resets the kernel,
 *          replies KernelResponse to the original caller, then tells
 *          the parent pool actor KernelReady.
 * Shutdown: onPostStop() shuts the kernel down gracefully.
 *
 * @implements AbstractActor<object>
 */
final class KernelActor extends AbstractActor
{
    private ?HttpKernelInterface $kernel = null;

    private ?ResetInterface $resetter = null;

    /**
     * @param Closure(array<string, mixed>): HttpKernelInterface $kernelFactory
     */
    public function __construct(
        private readonly Closure $kernelFactory,
        private readonly ActorSystem $system,
        private readonly Runtime $runtime,
    ) {}

    /**
     * @param ActorContext<object> $ctx
     */
    #[Override]
    public function onPreStart(ActorContext $ctx): void
    {
        /** @var array<string, mixed> $env */
        $env = $_SERVER + $_ENV;

        $kernel = ($this->kernelFactory)($env);

        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
            $container = $kernel->getContainer();
            $container->set('nexus.actor_system', $this->system);
            $container->set('nexus.runtime', $this->runtime);

            if ($container->has('services_resetter')) {
                $resetter = $container->get('services_resetter');
                assert($resetter instanceof ResetInterface);
                $this->resetter = $resetter;
            }
        }

        $this->kernel = $kernel;
    }

    /**
     * @param ActorContext<object> $ctx
     */
    #[Override]
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof KernelDispatch) {
            return Behavior::unhandled();
        }

        assert($this->kernel !== null, 'Kernel must be booted before handling requests');

        $response = $this->kernel->handle($message->request);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($message->request, $response);
        }

        $this->resetter?->reset();

        $message->replyTo->tell(new KernelResponse($response));
        $ctx->parent()?->tell(new KernelReady($ctx->self()));

        return Behavior::same();
    }

    /**
     * @param ActorContext<object> $ctx
     */
    #[Override]
    public function onPostStop(ActorContext $ctx): void
    {
        if ($this->kernel instanceof KernelInterface) {
            $this->kernel->shutdown();
        }
    }
}
