<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Override;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * @internal Default container used by WsApplication when none is provided.
 * HandlerInstantiator falls through to default values or throws for
 * required parameters that are not FromContext-marked.
 */
final class EmptyContainer implements ContainerInterface
{
    #[Override]
    public function get(string $id): mixed
    {
        throw new class ("Empty container has no entry for {$id}.") extends RuntimeException implements NotFoundExceptionInterface {};
    }

    #[Override]
    public function has(string $id): bool
    {
        return false;
    }
}
