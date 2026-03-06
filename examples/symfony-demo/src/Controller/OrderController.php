<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\PlaceOrder;
use App\Service\RequestContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    public function __construct(private readonly MessageBusInterface $bus) {}

    #[Route('/orders', methods: ['POST'])]
    public function place(Request $request, RequestContext $ctx): JsonResponse
    {
        /** @var array{customerId: string, productId: string, qty: int} $body */
        $body = json_decode((string) $request->getContent(), true);

        $this->bus->dispatch(new PlaceOrder($body['customerId'], $body['productId'], $body['qty']));

        return new JsonResponse([
            'requestId' => $ctx->requestId,
            'status'    => 'queued',
        ], 202);
    }
}
