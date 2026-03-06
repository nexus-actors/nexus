<?php

declare(strict_types=1);

namespace App\Actor;

use App\Actor\Message\GetStock;
use App\Actor\Message\StockLevel;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Actor(ActorType::Isolated, 'inventory')]
final class InventoryActor implements ActorHandler
{
    private const int STOCK_TTL = 60;

    public function __construct(private readonly CacheInterface $cache) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof GetStock) {
            return Behavior::unhandled();
        }

        $levels = [];

        foreach ($message->productIds as $id) {
            /** @var int $stock */
            $stock = $this->cache->get(
                "inventory.stock.{$id}",
                static function (ItemInterface $item): int {
                    $item->expiresAfter(self::STOCK_TTL);
                    $item->tag(['inventory']);

                    return random_int(5, 50);
                },
            );

            $levels[$id] = $stock;
        }

        $ctx->reply(new StockLevel($levels));

        return Behavior::same();
    }
}
