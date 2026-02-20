<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NexusApp::class)]
final class NexusAppTest extends TestCase
{
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
