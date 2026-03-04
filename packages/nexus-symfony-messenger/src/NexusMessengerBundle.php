<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger;

use Monadial\Nexus\Symfony\Messenger\Compiler\MessengerActorPass;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusMessengerBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MessengerActorPass());
    }
}
