<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(BusRegistry::class)]
final class BusRegistryTest extends TestCase
{
    #[Test]
    public function constructionExposesAllFourFields(): void
    {
        $command = new StubCommandBus();
        $query = new StubQueryBus();
        $event = new StubEventBus();

        $registry = new BusRegistry(
            Profile::Sync,
            ['orders' => $command],
            ['reporting' => $query],
            ['outbox' => $event],
        );

        self::assertSame(Profile::Sync, $registry->profile);
        self::assertSame(['orders' => $command], $registry->commandBuses);
        self::assertSame(['reporting' => $query], $registry->queryBuses);
        self::assertSame(['outbox' => $event], $registry->eventBuses);
    }

    #[Test]
    public function commandReturnsSomeForRegisteredName(): void
    {
        $bus = new StubCommandBus();
        $registry = new BusRegistry(Profile::Sync, ['orders' => $bus], [], []);

        $result = $registry->command('orders');

        self::assertTrue($result->isSome());
        self::assertSame($bus, $result->getUnsafe());
    }

    #[Test]
    public function commandReturnsNoneForUnknownName(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new StubCommandBus()], [], []);

        self::assertTrue($registry->command('unknown')->isNone());
    }

    #[Test]
    public function commandNamesReturnsRegisteredKeys(): void
    {
        $registry = new BusRegistry(
            Profile::Sync,
            ['orders' => new StubCommandBus(), 'reporting' => new StubCommandBus()],
            [],
            [],
        );

        self::assertSame(['orders', 'reporting'], $registry->commandNames());
    }

    #[Test]
    public function queryAccessorsMirrorCommandAccessors(): void
    {
        $bus = new StubQueryBus();
        $registry = new BusRegistry(Profile::Sync, [], ['reporting' => $bus], []);

        self::assertSame($bus, $registry->query('reporting')->getUnsafe());
        self::assertTrue($registry->query('unknown')->isNone());
        self::assertSame(['reporting'], $registry->queryNames());
    }

    #[Test]
    public function eventAccessorsMirrorCommandAccessors(): void
    {
        $bus = new StubEventBus();
        $registry = new BusRegistry(Profile::Sync, [], [], ['outbox' => $bus]);

        self::assertSame($bus, $registry->event('outbox')->getUnsafe());
        self::assertTrue($registry->event('unknown')->isNone());
        self::assertSame(['outbox'], $registry->eventNames());
    }

    #[Test]
    public function validateRoutesPassesWhenAllRoutesResolveToRegisteredBuses(): void
    {
        $registry = new BusRegistry(
            Profile::Sync,
            ['orders' => new StubCommandBus(), 'reporting' => new StubCommandBus()],
            [],
            [],
        );

        $registry->validateRoutes([
            self::class => new RoutingResolution('reporting', RoutingResolution::class),
            stdClass::class => new RoutingResolution('orders', RoutingResolution::class),
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validateRoutesPassesForEmptyResolutions(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new StubCommandBus()], [], []);

        $registry->validateRoutes([]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validateRoutesThrowsWhenRouteTargetsUnregisteredBus(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new StubCommandBus()], [], []);

        $this->expectException(BusNameNotRegisteredException::class);
        $this->expectExceptionMessage('reporting');

        $registry->validateRoutes([
            stdClass::class => new RoutingResolution('reporting', RoutingResolution::class),
        ]);
    }

    #[Test]
    public function validateRoutesErrorListsRegisteredBusNames(): void
    {
        $registry = new BusRegistry(
            Profile::Sync,
            ['orders' => new StubCommandBus(), 'shipments' => new StubCommandBus()],
            [],
            [],
        );

        try {
            $registry->validateRoutes([
                stdClass::class => new RoutingResolution('unknown', RoutingResolution::class),
            ]);
            self::fail('expected BusNameNotRegisteredException');
        } catch (BusNameNotRegisteredException $e) {
            self::assertStringContainsString('orders', $e->getMessage());
            self::assertStringContainsString('shipments', $e->getMessage());
        }
    }
}

final class StubCommandBus implements CommandBus
{
    #[Override]
    public function dispatchCommand(Command $command): void
    {
        // stub never invoked
    }

    #[Override]
    public function tryDispatch(Command $command): Either
    {
        /** @psalm-suppress InvalidReturnStatement — stub never invoked */
        return Either::right(new Accepted());
    }
}

final class StubQueryBus implements QueryBus
{
    #[Override]
    public function dispatchQuery(Query $query): mixed
    {
        return null;
    }

    #[Override]
    public function tryAsk(Query $query): Either
    {
        /** @psalm-suppress InvalidReturnStatement — stub never invoked */
        return Either::right(null);
    }
}

final class StubEventBus implements EventBus
{
    #[Override]
    public function publishEvent(DomainEvent $event): void
    {
        // stub never invoked
    }

    #[Override]
    public function tryPublish(DomainEvent $event): Either
    {
        /** @psalm-suppress InvalidReturnStatement — stub never invoked */
        return Either::right(new Accepted());
    }
}
