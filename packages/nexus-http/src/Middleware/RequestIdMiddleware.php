<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Ulid;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(public string $headerName = 'X-Request-Id') {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine($this->headerName);

        if ($id === '') {
            $id = (string) new Ulid();
            $request = $request->withHeader($this->headerName, $id);
        }

        $response = $handler->handle($request);

        return $response->withHeader($this->headerName, $id);
    }
}
