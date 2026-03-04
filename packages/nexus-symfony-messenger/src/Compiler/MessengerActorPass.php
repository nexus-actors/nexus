<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Compiler;

use Override;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessengerActorPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'nexus-symfony-messenger requires ext-swoole. '
                . 'The ask() pattern suspends coroutines and cannot work without Swoole.',
            );
        }
    }
}
