<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\CommandRouter;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CommandRouter::class)]
final class CommandRouterTest extends TestCase
{
    #[Test]
    public function routeForReturnsRegisteredBusWhenStrategyResolves(): void
    {
        $bus = new StubCommandBus();
        $registry = new BusRegistry(Profile::Sync, ['orders' => $bus], [], []);
        $strategy = new FixedResolutionStrategy(
            Option::some(new RoutingResolution('orders', FixedResolutionStrategy::class)),
        );

        $router = new CommandRouter($registry, $strategy);

        self::assertSame($bus, $router->routeFor(stdClass::class));
    }

    #[Test]
    public function routeForThrowsWhenStrategyResolvesToUnknownBus(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new StubCommandBus()], [], []);
        $strategy = new FixedResolutionStrategy(
            Option::some(new RoutingResolution('reporting', FixedResolutionStrategy::class)),
        );

        $router = new CommandRouter($registry, $strategy);

        $this->expectException(BusNameNotRegisteredException::class);
        $this->expectExceptionMessage('reporting');

        $router->routeFor(stdClass::class);
    }

    #[Test]
    public function routeForThrowsWhenStrategyReturnsNone(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new StubCommandBus()], [], []);
        $strategy = new FixedResolutionStrategy(Option::none());

        $router = new CommandRouter($registry, $strategy);

        $this->expectException(BusNameNotRegisteredException::class);

        $router->routeFor(stdClass::class);
    }

    #[Test]
    public function routeForResolvesViaCompositeDefaultFallback(): void
    {
        $bus = new StubCommandBus();
        $registry = new BusRegistry(Profile::Sync, ['default-bus' => $bus], [], []);
        $composite = new Composite([], 'default-bus');

        $router = new CommandRouter($registry, $composite);

        self::assertSame($bus, $router->routeFor(stdClass::class));
    }
}

final class FixedResolutionStrategy implements RoutingStrategy
{
    /** @param Option<RoutingResolution> $answer */
    public function __construct(private readonly Option $answer) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return $this->answer;
    }
}
