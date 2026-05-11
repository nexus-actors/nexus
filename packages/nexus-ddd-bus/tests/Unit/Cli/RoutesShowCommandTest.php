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
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

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
        $tester = new CommandTester(new RoutesShowCommand($registry, $strategy));

        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertStringContainsString('Registered command buses:', $output);
        self::assertStringContainsString('orders', $output);
        self::assertStringContainsString('payments', $output);
    }

    #[Test]
    public function namedClassRendersTheResolvedRoute(): void
    {
        $registry = new BusRegistry(Profile::Sync, ['orders' => new FakeCommandBus()], [], []);
        $strategy = new Composite([
            (new ExplicitOnly())->explicit('App\\PlaceOrder', 'orders'),
        ], 'orders');
        $tester = new CommandTester(new RoutesShowCommand($registry, $strategy));

        $exitCode = $tester->execute(['message-class' => 'App\\PlaceOrder']);
        $output = $tester->getDisplay();

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertStringContainsString('App\\PlaceOrder', $output);
        self::assertStringContainsString('bus `orders`', $output);
        self::assertStringContainsString('ExplicitOnly', $output);
    }

    #[Test]
    public function carriesSymfonyAsCommandName(): void
    {
        $command = new RoutesShowCommand(
            new BusRegistry(Profile::Sync, [], [], []),
            new ExplicitOnly(),
        );

        self::assertSame('ddd:routes:show', $command->getName());
    }
}

final class FakeCommandBus implements CommandBus
{
    #[Override]
    public function dispatchCommand(Command $command): void
    {
        // intentional no-op — fixture used only for routing-resolution tests.
    }

    #[Override]
    public function tryDispatch(Command $command): Either
    {
        return Either::right(new Accepted());
    }
}
