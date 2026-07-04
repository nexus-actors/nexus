<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrderView;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

final readonly class ListOrdersHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        EntityManagerInterface $em,
    ): ResponseInterface {
        if (!$principal->hasRole('ops')) {
            return new Psr7Response(403, ['Content-Type' => 'application/json'], '{"error":"role ops required"}');
        }

        $tenant = (string) ($principal->claims()['tenant'] ?? '');

        /** @var array<int, OrderView> $rows */
        $rows = $em->getRepository(OrderView::class)->findBy(
            ['tenantId' => $tenant],
            ['updatedAt' => 'DESC'],
            100,
        );

        $resources = array_map(
            static fn(OrderView $row): OrderResource => OrderResource::fromView($row),
            $rows,
        );

        return JsonResponse::ok(['orders' => $resources]);
    }
}
