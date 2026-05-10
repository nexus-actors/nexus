<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingAuthorizationDeciderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingAuthorizationDeciderException::class)]
final class MissingAuthorizationDeciderExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithCommandClass(): void
    {
        $ex = MissingAuthorizationDeciderException::for('App\\Command\\PlaceOrder');

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('App\\Command\\PlaceOrder', $ex->getMessage());
    }
}
