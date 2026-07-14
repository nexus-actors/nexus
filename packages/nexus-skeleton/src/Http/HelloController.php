<?php

declare(strict_types=1);

namespace App\Http;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HelloController
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return JsonResponse::ok(['message' => 'Hello from Nexus!']);
    }
}
