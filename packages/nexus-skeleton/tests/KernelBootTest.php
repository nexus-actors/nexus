<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\Message\Greet;
use App\Support\Recorder;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

final class KernelBootTest extends TestCase
{
    #[Test]
    public function bootsTheContainerAndAutoSpawnsAttributedActors(): void
    {
        $kernel = new Kernel(dirname(__DIR__), 'test-app');
        $system = $kernel->boot();

        $ref = $kernel->ref('greeter') ?? self::fail('greeter actor was not spawned');

        /** @var FiberRuntime $runtime */
        $runtime = $kernel->container()->get('nexus.runtime');
        $runtime->scheduleOnce(Duration::millis(20), static fn() => $ref->tell(new Greet('world')));
        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        /** @var Recorder $recorder */
        $recorder = $kernel->container()->get(Recorder::class);
        self::assertSame(['world'], $recorder->greeted);
    }
}
