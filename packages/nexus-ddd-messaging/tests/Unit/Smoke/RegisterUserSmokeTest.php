<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\WithRootContext;
use Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures\RegisterUser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RegisterUserSmokeTest extends TestCase
{
    #[Test]
    public function dispatchesViaRecordingBusInsideRootContext(): void
    {
        $bus = new RecordingCommandBus();
        $cmd = new RegisterUser('user-1', 'a@b.c');

        WithRootContext::default()->run(static function () use ($bus, $cmd): void {
            $bus->dispatchCommand($cmd);
        });

        self::assertSame([$cmd], $bus->recorded());
    }
}
