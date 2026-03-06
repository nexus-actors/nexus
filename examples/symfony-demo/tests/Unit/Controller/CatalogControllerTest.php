<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Actor\Message\GetProduct;
use App\Actor\Message\GetProducts;
use App\Actor\Message\GetStock;
use App\Actor\Message\Product;
use App\Actor\Message\ProductDetail;
use App\Actor\Message\ProductList;
use App\Actor\Message\StockLevel;
use App\Controller\CatalogController;
use App\Service\RequestContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

#[CoversClass(CatalogController::class)]
final class CatalogControllerTest extends TestCase
{
    #[Test]
    public function list_returnsFutureThatResolvesToProductsWithStock(): void
    {
        $product = new Product('A chair', 'chair-001', 'Chair', 99.99);
        $list    = new ProductList([$product]);
        $stock   = new StockLevel(['chair-001' => 10]);

        $catalogSlot = $this->createMock(FutureSlot::class);
        $catalogSlot->method('await')->willReturn($list);
        $catalogSlot->method('isResolved')->willReturn(false);

        $inventorySlot = $this->createMock(FutureSlot::class);
        $inventorySlot->method('await')->willReturn($stock);

        $catalogActor = $this->createMock(ActorRef::class);
        $catalogActor->method('ask')->willReturn(new Future($catalogSlot));

        $inventoryActor = $this->createMock(ActorRef::class);
        $inventoryActor->method('ask')->willReturn(new Future($inventorySlot));

        $controller = new CatalogController($catalogActor, $inventoryActor);
        $future     = $controller->list(new RequestContext());

        /** @var JsonResponse $response */
        $response = $future->await();
        /** @var array{products: array<array{id: string, stock: int}>, requestId: string} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertArrayHasKey('products', $data);
        self::assertArrayHasKey('requestId', $data);
        self::assertSame(10, $data['products'][0]['stock']);
    }

    #[Test]
    public function show_returnsFutureThatResolvesToProductDetail(): void
    {
        $product = new Product('A chair', 'chair-001', 'Chair', 99.99);
        $detail  = new ProductDetail($product);

        $slot = $this->createMock(FutureSlot::class);
        $slot->method('await')->willReturn($detail);
        $slot->method('isResolved')->willReturn(false);

        $catalogActor = $this->createMock(ActorRef::class);
        $catalogActor->method('ask')->willReturn(new Future($slot));

        $inventoryActor = $this->createStub(ActorRef::class);

        $controller = new CatalogController($catalogActor, $inventoryActor);
        $future     = $controller->show('chair-001');

        /** @var JsonResponse $response */
        $response = $future->await();
        /** @var array{id: string} $data */
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame('chair-001', $data['id']);
    }
}
