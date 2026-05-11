<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Cli;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Cli\RoutesShowCommand;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\ExplicitOnly;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutesShowCommand::class)]
final class RoutesShowCommandTest extends TestCase
{
    #[Test]
    public function emptyArgsRendersAllRegisteredCommandBuses(): void
    {
        $registry = new BusRegistry(
            Profile::Sync,
            ['orders' => new FakeCommandBus(), 'payments' => new FakeCommandBus()],
            [],
            [],
        );
        $strategy = new ExplicitOnly();
        $command = new RoutesShowCommand($registry, $strategy);

        $output = $command->run([]);

        self::assertStringContainsString('Registered command buses:', $output);
        self::assertStringContainsString('orders', $output);
        self::assertStringContainsString('payments', $output);
    }

    #[Test]
    public function nonEmptyArgsRendersTheResolvedRoute(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new FakeCommandBus()], [], []);
        $strategy = new Composite([
            new ExplicitOnly()->explicit('App\\PlaceOrder', 'orders'),
        ], 'orders');
        $command = new RoutesShowCommand($registry, $strategy);

        $output = $command->run(['App\\PlaceOrder']);

        self::assertStringContainsString('App\\PlaceOrder', $output);
        self::assertStringContainsString('bus `orders`', $output);
        self::assertStringContainsString('ExplicitOnly', $output);
    }
}

final class FakeCommandBus implements CommandBus
{
    #[Override]
    public function dispatchCommand(Command $command): void {}

    #[Override]
    public function tryDispatch(Command $command): Either
    {
        return Either::right(new Accepted());
    }
}
