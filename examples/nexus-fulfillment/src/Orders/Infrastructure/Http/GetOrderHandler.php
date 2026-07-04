<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrderView;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

final readonly class GetOrderHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        string $id,
        EntityManagerInterface $em,
    ): ResponseInterface {
        if (!$principal->hasRole('ops')) {
            return new Psr7Response(403, ['Content-Type' => 'application/json'], '{"error":"role ops required"}');
        }

        try {
            $orderId = OrderId::fromString($id);
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        /** @var OrderView|null $row */
        $row = $em->find(OrderView::class, $orderId->value);

        if ($row === null) {
            return Response::notFound('order not found');
        }

        $tenant = (string) ($principal->claims()['tenant'] ?? '');

        if ($row->tenantId !== $tenant) {
            return Response::notFound('order not found');
        }

        return JsonResponse::ok([
            'cancelReason' => $row->cancelReason,
            'currency' => $row->currency,
            'lineCount' => $row->lineCount,
            'orderId' => $row->id,
            'status' => $row->status,
            'totalCents' => $row->totalAmount,
            'updatedAt' => $row->updatedAt->format('c'),
        ]);
    }
}
