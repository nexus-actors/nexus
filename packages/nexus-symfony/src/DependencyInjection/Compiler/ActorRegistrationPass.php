<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection\Compiler;

use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ActorRegistrationPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        // Implementation in Task 12
    }
}
