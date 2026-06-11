<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Exception\PerRequestScopeDisposedException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerRequestActorScope::class)]
final class PerRequestActorScopeTest extends TestCase
{
    #[Test]
    public function dispose_is_idempotent_and_noop_when_nothing_spawned(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');

        $scope->dispose();
        $scope->dispose();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function spawn_after_dispose_throws_scope_disposed(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);
        $scope = new PerRequestActorScope($system, ['saga' => $entry], 'r-1');

        $scope->dispose();

        $this->expectException(PerRequestScopeDisposedException::class);
        $scope->spawn('saga');
    }

    #[Test]
    public function spawn_creates_actor_and_memoizes_it(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $entry = new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null);
        $scope = new PerRequestActorScope($system, ['saga' => $entry], 'r-1');

        $a = $scope->spawn('saga');
        $b = $scope->spawn('saga');

        self::assertSame($a, $b);
        self::assertTrue($scope->hasSpawned('saga'));
        self::assertSame('/user/saga-r-1', (string) $a->path());
    }

    #[Test]
    public function unknown_name_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');

        $this->expectException(UnknownActorException::class);
        $scope->spawn('nope');
    }

    /** @return Props<object> */
    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg): Behavior => Behavior::same()));
    }
}
