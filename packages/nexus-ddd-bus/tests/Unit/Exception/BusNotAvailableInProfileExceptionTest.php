<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\BusNotAvailableInProfileException;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusNotAvailableInProfileException::class)]
final class BusNotAvailableInProfileExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithBusAndProfile(): void
    {
        $ex = BusNotAvailableInProfileException::for('commands.async', Profile::Sync);

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('commands.async', $ex->getMessage());
        self::assertStringContainsString('sync', $ex->getMessage());
    }
}
