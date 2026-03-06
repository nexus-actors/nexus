<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Coroutine;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
final class CoroutineScopeListener
{
    public function __construct(
        private readonly CoroutineScope $scope,
        private readonly ServiceLocator $scopedLocator,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $factories = [];
        $locator   = $this->scopedLocator;

        foreach ($this->scopedLocator->getProvidedServices() as $id => $_type) {
            $factories[$id] = static fn(): object => $locator->get($id);
        }

        $this->scope->initialize($factories);
    }
}
