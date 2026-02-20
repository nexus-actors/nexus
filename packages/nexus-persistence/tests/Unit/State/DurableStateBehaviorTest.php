<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DurableStateBehavior::class)]
final class DurableStateBehaviorTest extends TestCase
{
    private PersistenceId $persistenceId;
    private Closure $commandHandler;
    private stdClass $emptyState;

    protected function setUp(): void
    {
        $this->persistenceId = PersistenceId::of('TestEntity', 'test-1');
        $this->emptyState = new stdClass();
        $this->commandHandler = static fn(object $state, ActorContext $ctx, object $msg): DurableEffect => DurableEffect::none();
    }

    #[Test]
    public function createReturnsDurableStateBehaviorInstance(): void
    {
        $builder = DurableStateBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
        );

        self::assertInstanceOf(DurableStateBehavior::class, $builder);
    }

    #[Test]
    public function withStateStoreReturnsNewImmutableInstance(): void
    {
        $original = DurableStateBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
        );

        $withStore = $original->withStateStore(new InMemoryDurableStateStore());

        self::assertInstanceOf(DurableStateBehavior::class, $withStore);
        self::assertNotSame($original, $withStore);
    }

    #[Test]
    public function toBehaviorReturnsBehaviorWhenStoreIsSet(): void
    {
        $behavior = DurableStateBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
        )
            ->withStateStore(new InMemoryDurableStateStore())
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    #[Test]
    public function toBehaviorThrowsLogicExceptionWhenStoreIsNotSet(): void
    {
        $builder = DurableStateBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DurableStateStore is required');

        $builder->toBehavior();
    }

    #[Test]
    public function fullBuilderChainReturnsBehavior(): void
    {
        $behavior = DurableStateBehavior::create(
            $this->persistenceId,
            $this->emptyState,
            $this->commandHandler,
        )
            ->withStateStore(new InMemoryDurableStateStore())
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }
}
