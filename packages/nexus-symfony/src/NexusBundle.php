<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony;

use Monadial\Nexus\Symfony\DependencyInjection\Compiler\ActorRegistrationPass;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\CoroutineScopedPass;
use Monadial\Nexus\Symfony\DependencyInjection\Compiler\GlobalActorPass;
use Monadial\Nexus\Symfony\DependencyInjection\NexusExtension;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ActorRegistrationPass());
        $container->addCompilerPass(new CoroutineScopedPass());
        $container->addCompilerPass(new GlobalActorPass());
    }

    #[Override]
    protected function createContainerExtension(): NexusExtension
    {
        return new NexusExtension();
    }
}
