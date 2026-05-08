<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Core\Exception\ApplyDuringReplayException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplyDuringReplayException::class)]
final class ApplyDuringReplayExceptionTest extends TestCase
{
    #[Test]
    public function inApplyMethodReturnsFrameworkException(): void
    {
        $e = ApplyDuringReplayException::inApplyMethod();

        self::assertInstanceOf(ApplyDuringReplayException::class, $e);
        self::assertInstanceOf(NexusDddException::class, $e);
    }

    #[Test]
    public function inApplyMethodMessageMentionsRecordThat(): void
    {
        $e = ApplyDuringReplayException::inApplyMethod();

        self::assertStringContainsString('recordThat()', $e->getMessage());
    }

    #[Test]
    public function inApplyMethodMessageMentionsReplay(): void
    {
        $e = ApplyDuringReplayException::inApplyMethod();

        self::assertStringContainsString('replay', $e->getMessage());
    }
}
