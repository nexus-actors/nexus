<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Discovery\Fixtures;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\Attribute\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Route('GET', '/readonly', name: 'readonly.action')]
final readonly class ReadonlyDiscoveredAction
{
    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}
