<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Discovery\Fixtures;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\Attribute\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('GET', '/discovered/{id}', name: 'discovered.show', middleware: ['App\\Mw'])]
final class DiscoveredAction
{
    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}
