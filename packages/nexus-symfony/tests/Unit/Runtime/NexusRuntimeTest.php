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
#[CoversClass(NexusRunner::class)]
final class NexusRuntimeTest extends TestCase
{
    #[Test]
    public function getRunnerReturnsNexusRunnerWhenFactoryStoredViaGetResolver(): void
    {
        $runtime = new NexusRuntime(['host' => '127.0.0.1', 'port' => 8080]);
        $kernel  = $this->createStub(HttpKernelInterface::class);

        $runtime->getResolver(static fn() => $kernel);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(NexusRunner::class, $runner);
    }

    #[Test]
    public function getRunnerReturnsNexusRunnerWithFallbackWhenNoResolverCalled(): void
    {
        $runtime = new NexusRuntime(['host' => '127.0.0.1', 'port' => 8080]);
        $kernel  = $this->createStub(HttpKernelInterface::class);

        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(NexusRunner::class, $runner);
    }

    #[Test]
    public function defaultOptionsAreMergedWithProvided(): void
    {
        $runtime = new NexusRuntime(['port' => 9090]);
        $kernel  = $this->createStub(HttpKernelInterface::class);

        // getRunner() must not throw — default options (host, workers) merged with provided (port)
        $runner = $runtime->getRunner($kernel);

        self::assertInstanceOf(NexusRunner::class, $runner);
    }
}
