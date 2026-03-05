<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine;

use Monadial\Nexus\Symfony\Doctrine\Compiler\DoctrineCompilerPass;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NexusDoctrineBundle extends Bundle
{
    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineCompilerPass());
    }
}
