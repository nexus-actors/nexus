<?php

declare(strict_types=1);

namespace App\Tests\Unit\Actor;

use App\Actor\InventoryActor;
use App\Actor\Message\GetStock;
use App\Actor\Message\StockLevel;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[CoversClass(InventoryActor::class)]
final class InventoryActorTest extends TestCase
{
    #[Test]
    public function handleGetStock_repliesWithStockLevels(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key, callable $callback): int {
                $item = new class implements ItemInterface {
                    public function getKey(): string { return ''; }
                    public function get(): mixed { return null; }
                    public function isHit(): bool { return false; }
                    public function set(mixed $value): static { return $this; }
                    public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
                    public function expiresAfter(int|\DateInterval|null $time): static { return $this; }
                    public function tag(\Traversable|array|string $tags): static { return $this; }
                    public function getMetadata(): array { return []; }
                };

                return $callback($item);
            },
        );

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())->method('reply')->with(
            self::callback(static function (StockLevel $level): bool {
                return array_key_exists('chair-001', $level->levels)
                    && array_key_exists('desk-001', $level->levels);
            }),
        );

        $actor    = new InventoryActor($cache);
        $behavior = $actor->handle($ctx, new GetStock(['chair-001', 'desk-001']));

        self::assertInstanceOf(SameBehavior::class, $behavior);
    }

    #[Test]
    public function handleGetStock_emptyList_repliesWithEmptyLevels(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $ctx   = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())->method('reply')->with(
            self::callback(static fn(StockLevel $s): bool => $s->levels === []),
        );

        $actor    = new InventoryActor($cache);
        $behavior = $actor->handle($ctx, new GetStock([]));

        self::assertInstanceOf(SameBehavior::class, $behavior);
    }

    #[Test]
    public function handleUnknownMessage_returnsUnhandled(): void
    {
        $cache    = $this->createStub(CacheInterface::class);
        $ctx      = $this->createStub(ActorContext::class);
        $actor    = new InventoryActor($cache);
        $behavior = $actor->handle($ctx, new \stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $behavior);
    }
}
