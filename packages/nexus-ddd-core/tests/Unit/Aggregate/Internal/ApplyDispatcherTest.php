<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal;

use Monadial\Nexus\Ddd\Core\Aggregate\Attribute\AppliesTo;
use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplyDispatcher::class)]
#[CoversClass(AppliesTo::class)]
final class ApplyDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchInvokesApplyMethodMatchingShortName(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $dispatcher->dispatch($aggregate, new SomeEvent('hello'));

        self::assertSame('hello', $aggregate->captured);
    }

    #[Test]
    public function missingApplyMethodThrows(): void
    {
        $aggregate = new TargetAggregate();
        $dispatcher = new ApplyDispatcher();

        $this->expectException(ApplyMethodNotFoundException::class);
        $dispatcher->dispatch($aggregate, new UnhandledEvent());
    }

    #[Test]
    public function dispatchIsCachedAcrossInvocations(): void
    {
        $dispatcher = new ApplyDispatcher();
        $aggregate = new TargetAggregate();
        $dispatcher->dispatch($aggregate, new SomeEvent('a'));
        $dispatcher->dispatch($aggregate, new SomeEvent('b'));    // 2nd call uses cache

        self::assertSame('b', $aggregate->captured);
    }

    #[Test]
    public function appliesToAttributeOverridesShortNameConvention(): void
    {
        $aggregate = new VersionedAggregate();
        $dispatcher = new ApplyDispatcher();

        // ItemPicked with #[AppliesTo('applyItemPickedV2')] routes to the
        // explicitly-named method, not the conventional applyItemPicked.
        $dispatcher->dispatch($aggregate, new ItemPicked('widget'));

        self::assertSame('widget', $aggregate->capturedV2);
        self::assertSame('', $aggregate->capturedV1);
    }

    #[Test]
    public function appliesToBypassesShortNameCollisionDetection(): void
    {
        $aggregate = new VersionedAggregate();
        $dispatcher = new ApplyDispatcher();

        // Both ItemPickedV1 (conventional) and ItemPicked (explicit) would
        // short-name to "ItemPicked" — but the explicit attribute opts the
        // V2 event out of the index, so no collision is reported.
        $dispatcher->dispatch($aggregate, new ItemPickedV1('first'));
        $dispatcher->dispatch($aggregate, new ItemPicked('second'));

        self::assertSame('first', $aggregate->capturedV1);
        self::assertSame('second', $aggregate->capturedV2);
    }

    #[Test]
    public function shortNameCollisionWithoutAppliesToStillThrows(): void
    {
        $aggregate = new CollidingAggregate();
        $dispatcher = new ApplyDispatcher();

        // First dispatch caches under conventional resolution.
        $dispatcher->dispatch($aggregate, new \Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal\NamespaceA\Collided('a'));

        // Second event has same short name "Collided" but no #[AppliesTo] —
        // collision detected, exception thrown, prior cache invalidated.
        $this->expectException(ApplyMethodAmbiguousException::class);
        $dispatcher->dispatch($aggregate, new \Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Internal\NamespaceB\Collided('b'));
    }
}

final class TargetAggregate
{
    public string $captured = '';

    private function applySomeEvent(SomeEvent $e): void
    {
        $this->captured = $e->payload;
    }
}

final readonly class SomeEvent implements DomainEvent
{
    public function __construct(public string $payload) {}
}

final readonly class UnhandledEvent implements DomainEvent {}

/**
 * Holds two apply-method versions; one routed by convention (V1), one
 * routed explicitly via #[AppliesTo] (V2 escape hatch).
 */
final class VersionedAggregate
{
    public string $capturedV1 = '';
    public string $capturedV2 = '';

    private function applyItemPickedV1(ItemPickedV1 $e): void
    {
        $this->capturedV1 = $e->sku;
    }

    private function applyItemPickedV2(ItemPicked $e): void
    {
        $this->capturedV2 = $e->sku;
    }
}

/** Conventional-resolution event — routes to applyItemPickedV1. */
final readonly class ItemPickedV1 implements DomainEvent
{
    public function __construct(public string $sku) {}
}

/** Versioned event — routes via attribute to applyItemPickedV2. */
#[AppliesTo('applyItemPickedV2')]
final readonly class ItemPicked implements DomainEvent
{
    public function __construct(public string $sku) {}
}

final class CollidingAggregate
{
    public string $captured = '';

    private function applyCollided(NamespaceA\Collided|NamespaceB\Collided $e): void
    {
        $this->captured = $e->payload;
    }
}
