<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryLevel;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

use function array_map;

#[RequiresRole('ops')]
final readonly class ListInventoryHandler
{
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        EntityManagerInterface $em,
    ): ResponseInterface {
        $tenant = (string) ($principal->claims()['tenant'] ?? '');

        /** @var array<int, InventoryLevel> $rows */
        $rows = $em->getRepository(InventoryLevel::class)->findBy(
            ['tenantId' => $tenant],
            ['sku' => 'ASC'],
            100,
        );

        $items = array_map(
            static fn(InventoryLevel $row): InventoryResource => new InventoryResource(
                new Sku($row->sku),
                $row->onHand,
                $row->available(),
            ),
            $rows,
        );

        return JsonResponse::ok(['items' => $items]);
    }
}
