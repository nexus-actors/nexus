<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\Exception\UnknownRootActorException;
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\App\StartedApp;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NexusApp::class)]
#[CoversClass(StartedApp::class)]
final class NexusAppTest extends TestCase
{
    #[Test]
    public function startReturnsStartedAppWithNamedTypedHandles(): void
    {
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $started = NexusApp::create('test-app')
            ->actor('orders', $props)
            ->actor('payments', $props)
            ->start(new TestRuntime(new TestClock()));

        self::assertInstanceOf(StartedApp::class, $started);
        self::assertInstanceOf(ActorRef::class, $started->ref('orders'));
        self::assertInstanceOf(ActorRef::class, $started->ref('payments'));
        self::assertTrue($started->has('orders'));
        self::assertFalse($started->has('missing'));
        // Registration order is preserved so callers can reason about spawn ordering.
        self::assertSame(['orders', 'payments'], array_keys($started->refs()));
    }

    #[Test]
    public function startedAppRefThrowsForUnknownActor(): void
    {
        $started = NexusApp::create('test-app')->start(new TestRuntime(new TestClock()));

        $this->expectException(UnknownRootActorException::class);

        $started->ref('nope');
    }

    #[Test]
    public function onStartReceivesStartedAppForHandleLookup(): void
    {
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $lookedUp = null;

        NexusApp::create('test-app')
            ->actor('orders', $props)
            ->onStart(static function (StartedApp $app) use (&$lookedUp): void {
                $lookedUp = $app->ref('orders');
            })
            ->start(new TestRuntime(new TestClock()));

        self::assertInstanceOf(ActorRef::class, $lookedUp);
    }

    #[Test]
    public function startedAppExposesTheUnderlyingSystem(): void
    {
        $started = NexusApp::create('sys-test')->start(new TestRuntime(new TestClock()));

        self::assertSame('sys-test', $started->system()->name());
    }

    #[Test]
    public function createReturnsApp(): void
    {
        $app = NexusApp::create('test-app');

        self::assertInstanceOf(NexusApp::class, $app);
    }

    #[Test]
    public function actorRegistrationIsChainable(): void
    {
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $app = NexusApp::create('test-app')
            ->actor('orders', $props)
            ->actor('payments', $props);

        self::assertInstanceOf(NexusApp::class, $app);
    }

    #[Test]
    public function runSpawnsAllRegisteredActors(): void
    {
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $runtime = new TestRuntime(new TestClock());
        $started = [];

        $app = NexusApp::create('test-app')
            ->actor('orders', $props)
            ->actor('payments', $props)
            ->onStart(static function ($system) use (&$started): void {
                $started[] = 'started';
            });

        $app->run($runtime);

        self::assertCount(1, $started);
    }

    #[Test]
    public function nameReturnsAppName(): void
    {
        $app = NexusApp::create('my-app');

        self::assertSame('my-app', $app->name());
    }

    #[Test]
    public function actorDefinitionsAreAccessible(): void
    {
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $app = NexusApp::create('test-app')
            ->actor('orders', $props)
            ->actor('payments', $props);

        self::assertCount(2, $app->actors());
    }
}
