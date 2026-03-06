<?php

declare(strict_types=1);

namespace App\Tests\Unit\Actor;

use App\Actor\CatalogActor;
use App\Actor\Message\GetProduct;
use App\Actor\Message\GetProducts;
use App\Actor\Message\ProductDetail;
use App\Actor\Message\ProductList;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[CoversClass(CatalogActor::class)]
final class CatalogActorTest extends TestCase
{
    #[Test]
    public function handleGetProducts_repliesWithProductList(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key, callable $callback): object {
                $item = new class implements ItemInterface {
                    public function getKey(): string { return ''; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(int|\DateInterval|null $time): static { return $this; }
                    public function tag(array|string $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };

                return $callback($item);
            },
        );

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())->method('reply')->with(self::isInstanceOf(ProductList::class));

        $actor    = new CatalogActor($cache);
        $behavior = $actor->handle($ctx, new GetProducts());

        self::assertSame(Behavior::same(), $behavior);
    }

    #[Test]
    public function handleGetProduct_knownId_repliesWithProductDetail(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key, callable $callback): object {
                $item = new class implements ItemInterface {
                    public function getKey(): string { return ''; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(int|\DateInterval|null $time): static { return $this; }
                    public function tag(array|string $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };

                return $callback($item);
            },
        );

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())->method('reply')->with(self::isInstanceOf(ProductDetail::class));

        $actor    = new CatalogActor($cache);
        $behavior = $actor->handle($ctx, new GetProduct('chair-001'));

        self::assertSame(Behavior::same(), $behavior);
    }

    #[Test]
    public function handleGetProduct_unknownId_doesNotReply(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $ctx   = $this->createMock(ActorContext::class);
        $ctx->expects(self::never())->method('reply');

        $actor    = new CatalogActor($cache);
        $behavior = $actor->handle($ctx, new GetProduct('unknown-999'));

        self::assertSame(Behavior::same(), $behavior);
    }

    #[Test]
    public function handleUnknownMessage_returnsUnhandled(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $ctx   = $this->createStub(ActorContext::class);

        $actor    = new CatalogActor($cache);
        $behavior = $actor->handle($ctx, new \stdClass());

        self::assertSame(Behavior::unhandled(), $behavior);
    }
}
