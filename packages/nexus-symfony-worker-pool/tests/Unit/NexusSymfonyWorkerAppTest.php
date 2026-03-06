<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool\Tests\Unit;

use Monadial\Nexus\Symfony\WorkerPool\NexusSymfonyWorkerApp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(NexusSymfonyWorkerApp::class)]
final class NexusSymfonyWorkerAppTest extends TestCase
{
    #[Test]
    public function classExists(): void
    {
        self::assertTrue(class_exists(NexusSymfonyWorkerApp::class));
    }

    #[Test]
    public function runMethodExists(): void
    {
        self::assertTrue(method_exists(NexusSymfonyWorkerApp::class, 'run'));
    }

    #[Test]
    public function runMethodAcceptsKernelAndWorkerCount(): void
    {
        $reflection = new ReflectionMethod(NexusSymfonyWorkerApp::class, 'run');
        $params     = $reflection->getParameters();

        self::assertCount(2, $params);
        self::assertSame('kernel', $params[0]->getName());
        self::assertSame('workerCount', $params[1]->getName());
    }
}
