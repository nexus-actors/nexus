<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
final readonly class PoolExhaustedToServiceUnavailable implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses, private int $retryAfterSeconds = 1) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (PoolExhaustedException) {
            return $this->responses->createResponse(503)
                ->withHeader('Retry-After', (string) $this->retryAfterSeconds);
        }
    }
}
