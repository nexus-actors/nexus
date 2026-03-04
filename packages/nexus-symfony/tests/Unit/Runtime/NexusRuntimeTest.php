<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Runtime;

use Monadial\Nexus\Symfony\Runtime\NexusRunner;
use Monadial\Nexus\Symfony\Runtime\NexusRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(NexusRuntime::class)]
final class NexusRuntimeTest extends TestCase
{
    #[Test]
    public function getRunnerReturnsNexusRunner(): void
    {
        $runtime = new NexusRuntime(['host' => '127.0.0.1', 'port' => 8080]);
        $kernel  = $this->createStub(HttpKernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(NexusRunner::class, $runner);
    }

    #[Test]
    public function defaultOptionsAreApplied(): void
    {
        $runtime = new NexusRuntime([]);

        self::assertInstanceOf(NexusRuntime::class, $runtime);
    }
}
