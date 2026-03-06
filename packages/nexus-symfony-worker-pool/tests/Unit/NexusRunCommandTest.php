<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool\Tests\Unit;

use Monadial\Nexus\Symfony\WorkerPool\NexusRunCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(NexusRunCommand::class)]
final class NexusRunCommandTest extends TestCase
{
    #[Test]
    public function commandIsNamedNexusRun(): void
    {
        $command = new NexusRunCommand($this->createStub(KernelInterface::class));
        self::assertSame('nexus:run', $command->getName());
    }

    #[Test]
    public function commandHasWorkersOption(): void
    {
        $command = new NexusRunCommand($this->createStub(KernelInterface::class));
        self::assertTrue($command->getDefinition()->hasOption('workers'));
    }
}
