<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool\Tests\Unit;

use Monadial\Nexus\Symfony\WorkerPool\NexusConsumeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(NexusConsumeCommand::class)]
final class NexusConsumeCommandTest extends TestCase
{
    #[Test]
    public function commandIsNamedNexusConsume(): void
    {
        $kernel  = $this->createStub(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertSame('nexus:consume', $command->getName());
    }

    #[Test]
    public function commandHasTransportArgument(): void
    {
        $kernel  = $this->createStub(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertTrue($command->getDefinition()->hasArgument('transport'));
    }

    #[Test]
    public function commandHasWorkersOption(): void
    {
        $kernel  = $this->createStub(KernelInterface::class);
        $command = new NexusConsumeCommand($kernel);

        self::assertTrue($command->getDefinition()->hasOption('workers'));
    }
}
