<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/** @psalm-api */
final readonly class TransactionalDecorator implements RequestHandlerInterface
{
    public function __construct(private RequestHandlerInterface $inner) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $lease = $request->getAttribute(ConnectionLease::class);

        if (!$lease instanceof ConnectionLease) {
            throw new MissingConnectionScopeException();
        }

        $conn = $lease->get();
        $conn->beginTransaction();

        try {
            $response = $this->inner->handle($request);
            $conn->commit();

            return $response;
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }
}
