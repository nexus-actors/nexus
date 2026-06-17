<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Http;

use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class TransactionalEmDecorator implements RequestHandlerInterface
{
    public function __construct(private RequestHandlerInterface $inner) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $lease = $request->getAttribute(EntityManagerLease::class);

        if (!$lease instanceof EntityManagerLease) {
            throw new MissingEntityManagerScopeException();
        }

        $em = $lease->get();

        return $em->wrapInTransaction(fn(): ResponseInterface => $this->inner->handle($request));
    }
}
