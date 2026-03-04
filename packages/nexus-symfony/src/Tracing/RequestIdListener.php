<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 900)]
final class RequestIdListener
{
    public function __construct(private readonly CoroutineContextInterface $context) {}

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $ctx     = $this->context->current();

        $ctx['nexus.request_id']     = $request->headers->get('X-Request-Id') ?? $this->generateId();
        $ctx['nexus.correlation_id'] = $request->headers->get('X-Correlation-Id')
            ?? $ctx['nexus.request_id'];
    }
}
