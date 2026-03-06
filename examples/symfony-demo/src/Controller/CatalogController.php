<?php

declare(strict_types=1);

namespace App\Controller;

use App\Actor\Message\GetProduct;
use App\Actor\Message\GetProducts;
use App\Actor\Message\GetStock;
use App\Actor\Message\Product;
use App\Actor\Message\ProductDetail;
use App\Actor\Message\ProductList;
use App\Actor\Message\StockLevel;
use App\Service\RequestContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    private const array KNOWN_IDS = ['chair-001', 'desk-001', 'hub-001'];

    public function __construct(
        #[Autowire(service: 'nexus.actor_ref.catalog')]
        private readonly ActorRef $catalogActor,
        #[Autowire(service: 'nexus.actor_ref.inventory')]
        private readonly ActorRef $inventoryActor,
    ) {}

    /** @return Future<JsonResponse> */
    #[Route('/catalog', methods: ['GET'])]
    public function list(RequestContext $ctx): Future
    {
        $productsFuture = $this->catalogActor->ask(new GetProducts(), Duration::seconds(5));
        $stockFuture    = $this->inventoryActor->ask(new GetStock(self::KNOWN_IDS), Duration::seconds(5));
        $requestId      = $ctx->requestId;

        return $productsFuture->map(
            static function (ProductList $list) use ($stockFuture, $requestId): JsonResponse {
                /** @var StockLevel $stock */
                $stock = $stockFuture->await();

                return new JsonResponse([
                    'products'  => array_map(
                        static fn(Product $p) => [
                            ...$p->toArray(),
                            'stock' => $stock->levels[$p->id] ?? 0,
                        ],
                        $list->items,
                    ),
                    'requestId' => $requestId,
                ]);
            },
        );
    }

    /** @return Future<JsonResponse> */
    #[Route('/catalog/{id}', methods: ['GET'])]
    public function show(string $id): Future
    {
        return $this->catalogActor
            ->ask(new GetProduct($id), Duration::seconds(5))
            ->map(static fn(ProductDetail $d) => new JsonResponse($d->product->toArray()));
    }
}
