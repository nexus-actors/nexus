<?php
declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
final class CoroutineScopeListener
{
    /**
     * @param array<string, callable(): object> $factories
     */
    public function __construct(
        private readonly CoroutineScope $scope,
        private readonly array $factories,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->scope->initialize($this->factories);
    }
}
