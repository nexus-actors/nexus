<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ResponseIdListener
{
    public function __construct(private readonly CoroutineContext $context) {}

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $ctx = $this->context->current();

        if (!isset($ctx['nexus.request_id'])) {
            return;
        }

        $event->getResponse()->headers->set('X-Request-Id', (string) $ctx['nexus.request_id']);
    }
}
