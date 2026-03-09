<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Monadial\Nexus\Symfony\KernelPool\Message\HandleRequest;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelDispatch;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelReady;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelResponse;
use SplQueue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Manages a pool of KernelActor instances within a single worker thread.
 *
 * State:
 *   $idle      — refs of kernels available for immediate dispatch
 *   $pending   — requests waiting for an idle kernel (bounded by $maxPending)
 *   $inFlight  — map of kernel-path → replyTo for crash recovery
 *
 * Protocol:
 *   HandleRequest (via ask()) → dispatch to idle kernel, or queue, or reply 503
 *   KernelReady               → dispatch next pending request or return kernel to idle
 *   ChildFailed (signal)      → reply 503 to in-flight caller, respawn replacement kernel
 *
 * Use KernelPoolActor::props() to create the Props for spawning.
 */
final class KernelPoolActor
{
    /** @var SplQueue<ActorRef<object>> */
    private SplQueue $idle;

    /** @var SplQueue<array{replyTo: ActorRef<object>, request: Request}> */
    private SplQueue $pending;

    /** @var array<string, ActorRef<object>> */
    private array $inFlight = [];

    private int $kernelCounter;

    /**
     * @param Closure(array<string, mixed>): HttpKernelInterface $kernelFactory
     */
    private function __construct(
        private readonly Closure $kernelFactory,
        private readonly ActorSystem $system,
        private readonly Runtime $runtime,
        private readonly int $poolSize,
        private readonly int $maxPending,
    ) {
        $this->idle    = new SplQueue();
        $this->pending = new SplQueue();
        $this->kernelCounter = $poolSize;
    }

    /**
     * @param Closure(array<string, mixed>): HttpKernelInterface $kernelFactory
     * @return Props<object>
     */
    public static function props(
        Closure $kernelFactory,
        ActorSystem $system,
        Runtime $runtime,
        int $poolSize,
        int $maxPending,
    ): Props {
        return Props::fromBehavior(
            Behavior::setup(
                static function (ActorContext $ctx) use ($kernelFactory, $system, $runtime, $poolSize, $maxPending): Behavior {
                    $pool = new self($kernelFactory, $system, $runtime, $poolSize, $maxPending);

                    return $pool->init($ctx);
                },
            ),
        );
    }

    private function init(ActorContext $ctx): Behavior
    {
        $factory = $this->kernelFactory;
        $system  = $this->system;
        $runtime = $this->runtime;

        for ($i = 0; $i < $this->poolSize; $i++) {
            $ref = $ctx->spawn(
                Props::fromFactory(static fn() => new KernelActor($factory, $system, $runtime)),
                "kernel-{$i}",
            );
            $this->idle->enqueue($ref);
        }

        return Behavior::receive(fn(ActorContext $c, object $msg) => $this->receive($c, $msg))
            ->onSignal(fn(ActorContext $c, Signal $sig) => $this->onSignal($c, $sig));
    }

    private function receive(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof HandleRequest => $this->onHandleRequest($ctx, $message),
            $message instanceof KernelReady   => $this->onKernelReady($message),
            default                           => Behavior::unhandled(),
        };
    }

    private function onSignal(ActorContext $ctx, Signal $signal): Behavior
    {
        if ($signal instanceof ChildFailed) {
            $this->onChildFailed($ctx, $signal);
        }

        return Behavior::same();
    }

    private function onHandleRequest(ActorContext $ctx, HandleRequest $message): Behavior
    {
        $replyTo = $ctx->sender();
        assert($replyTo !== null, 'HandleRequest must be sent via ask()');

        if (!$this->idle->isEmpty()) {
            $kernel = $this->idle->dequeue();
            $path   = (string) $kernel->path();
            $this->inFlight[$path] = $replyTo;
            $kernel->tell(new KernelDispatch($message->request, $replyTo));

            return Behavior::same();
        }

        if (count($this->pending) < $this->maxPending) {
            $this->pending->enqueue([
                'replyTo' => $replyTo,
                'request' => $message->request,
            ]);

            return Behavior::same();
        }

        $replyTo->tell(new KernelResponse(
            new Response('Too many requests', Response::HTTP_SERVICE_UNAVAILABLE),
        ));

        return Behavior::same();
    }

    private function onKernelReady(KernelReady $message): Behavior
    {
        $ref  = $message->ref;
        $path = (string) $ref->path();

        unset($this->inFlight[$path]);

        if (!$this->pending->isEmpty()) {
            $item                  = $this->pending->dequeue();
            $this->inFlight[$path] = $item['replyTo'];
            $ref->tell(new KernelDispatch($item['request'], $item['replyTo']));

            return Behavior::same();
        }

        $this->idle->enqueue($ref);

        return Behavior::same();
    }

    private function onChildFailed(ActorContext $ctx, ChildFailed $signal): void
    {
        $path = (string) $signal->child->path();

        if (isset($this->inFlight[$path])) {
            $this->inFlight[$path]->tell(new KernelResponse(
                new Response('Service unavailable', Response::HTTP_SERVICE_UNAVAILABLE),
            ));
            unset($this->inFlight[$path]);
        }

        $factory     = $this->kernelFactory;
        $system      = $this->system;
        $runtime     = $this->runtime;
        $replacement = $ctx->spawn(
            Props::fromFactory(static fn() => new KernelActor($factory, $system, $runtime)),
            "kernel-{$this->kernelCounter}",
        );
        $this->kernelCounter++;

        $this->idle->enqueue($replacement);
    }
}
