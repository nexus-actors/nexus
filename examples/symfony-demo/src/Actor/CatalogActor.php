<?php

declare(strict_types=1);

namespace App\Actor;

use App\Actor\Message\GetProduct;
use App\Actor\Message\GetProducts;
use App\Actor\Message\Product;
use App\Actor\Message\ProductDetail;
use App\Actor\Message\ProductList;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Actor(ActorType::Isolated, 'catalog')]
final class CatalogActor implements ActorHandler
{
    private const int PRODUCT_TTL = 300;

    public function __construct(private readonly CacheInterface $cache) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof GetProducts) {
            return $this->handleGetProducts($ctx);
        }

        if ($message instanceof GetProduct) {
            return $this->handleGetProduct($ctx, $message);
        }

        return Behavior::unhandled();
    }

    private function handleGetProducts(ActorContext $ctx): Behavior
    {
        $items = array_map(
            function (Product $p): Product {
                /** @var Product $cached */
                $cached = $this->cache->get(
                    "catalog.product.{$p->id}",
                    static function (ItemInterface $item) use ($p): Product {
                        $item->expiresAfter(self::PRODUCT_TTL);
                        $item->tag(['catalog']);

                        return $p;
                    },
                );

                return $cached;
            },
            $this->seeds(),
        );

        $ctx->reply(new ProductList($items));

        return Behavior::same();
    }

    private function handleGetProduct(ActorContext $ctx, GetProduct $message): Behavior
    {
        $seed = $this->findSeed($message->id);

        if ($seed === null) {
            return Behavior::same();
        }

        /** @var Product $product */
        $product = $this->cache->get(
            "catalog.product.{$message->id}",
            static function (ItemInterface $item) use ($seed): Product {
                $item->expiresAfter(self::PRODUCT_TTL);
                $item->tag(['catalog']);

                return $seed;
            },
        );

        $ctx->reply(new ProductDetail($product));

        return Behavior::same();
    }

    private function findSeed(string $id): ?Product
    {
        foreach ($this->seeds() as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        return null;
    }

    /** @return Product[] */
    private function seeds(): array
    {
        return [
            new Product('A comfortable ergonomic chair', 'chair-001', 'Ergonomic Chair', 299.99),
            new Product('Height-adjustable standing desk', 'desk-001', 'Standing Desk', 499.99),
            new Product('High-performance USB-C hub', 'hub-001', 'USB-C Hub', 79.99),
        ];
    }
}
