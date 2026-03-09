<?php

declare(strict_types=1);

namespace App\Controller;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HomeAction
{
    public function __construct(private ActorSystem $actorSystem) {}

    #[Route('/', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['worker' => $this->actorSystem->name()]);
    }
}
