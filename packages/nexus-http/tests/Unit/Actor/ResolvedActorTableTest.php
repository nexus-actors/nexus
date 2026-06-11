<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PoolSingletonRequiresWorkerNodeException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedActorTable::class)]
final class ResolvedActorTableTest extends TestCase
{
    #[Test]
    public function per_request_actor_is_registered_but_not_spawned(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);

        $table = ResolvedActorTable::build([$entry], $system, workerNode: null);

        self::assertTrue($table->isPerRequest('saga'));
        $this->expectException(UnknownActorException::class);
        $table->resolve('saga');
    }

    #[Test]
    public function pool_singleton_without_worker_node_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::PoolSingleton, null, null);

        $this->expectException(PoolSingletonRequiresWorkerNodeException::class);
        $this->expectExceptionMessageMatches('/store/');
        ResolvedActorTable::build([$entry], $system, workerNode: null);
    }

    #[Test]
    public function worker_local_actor_is_spawned_during_build(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null);

        $table = ResolvedActorTable::build([$entry], $system, workerNode: null);

        $ref = $table->resolve('store');
        self::assertTrue($ref->isAlive());
        self::assertSame('/user/store', (string) $ref->path());
    }

    #[Test]
    public function has_any_is_true_for_resolved_and_per_request_entries(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entries = [
            new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null),
            new ActorRegistrationEntry('saga',  $this->noopProps(), ActorMode::PerRequest,  null, null),
        ];

        $table = ResolvedActorTable::build($entries, $system, workerNode: null);

        self::assertTrue($table->hasAny('store'));
        self::assertTrue($table->hasAny('saga'));
        self::assertFalse($table->hasAny('nope'));
    }

    #[Test]
    public function per_request_entries_returns_only_per_request_entries(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entries = [
            new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null),
            new ActorRegistrationEntry('saga',  $this->noopProps(), ActorMode::PerRequest,  null, null),
        ];

        $table = ResolvedActorTable::build($entries, $system, workerNode: null);

        $perRequest = $table->perRequestEntries();
        self::assertCount(1, $perRequest);
        self::assertArrayHasKey('saga', $perRequest);
        self::assertSame(ActorMode::PerRequest, $perRequest['saga']->mode);
    }

    /** @return Props<object> */
    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg): Behavior => Behavior::same()));
    }
}
