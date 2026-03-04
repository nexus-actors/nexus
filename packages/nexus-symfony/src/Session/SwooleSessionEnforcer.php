<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Session;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SwooleSessionEnforcer
{
    private const array INCOMPATIBLE_HANDLERS = [
        'session.handler.native_file',
        'session.handler.native',
    ];

    public static function assertCompatible(ContainerInterface $container): void
    {
        if (!$container->hasParameter('session.handler_id')) {
            return;
        }

        $handlerId = (string) $container->getParameter('session.handler_id');

        foreach (self::INCOMPATIBLE_HANDLERS as $incompatible) {
            if (str_contains($handlerId, $incompatible)) {
                throw new InvalidArgumentException(
                    'File sessions are not Swoole-compatible. '
                    . 'Configure a Redis or database session handler: '
                    . '$nexus->session(handler: SessionHandlerMode::Redis, dsn: "redis://localhost")',
                );
            }
        }
    }
}
