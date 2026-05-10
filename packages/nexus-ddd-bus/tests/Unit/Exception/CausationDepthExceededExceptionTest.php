<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusRuntimeException;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CausationDepthExceededException::class)]
final class CausationDepthExceededExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithDepthAndLimit(): void
    {
        $ex = CausationDepthExceededException::for(33, 32);

        self::assertInstanceOf(BusRuntimeException::class, $ex);
        self::assertInstanceOf(TerminalFailure::class, $ex);
        self::assertStringContainsString('33', $ex->getMessage());
        self::assertStringContainsString('32', $ex->getMessage());
    }
}
