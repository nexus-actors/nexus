<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusBootException;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingValidatorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingValidatorException::class)]
final class MissingValidatorExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithCommandClass(): void
    {
        $ex = MissingValidatorException::for('App\\Command\\PlaceOrder');

        self::assertInstanceOf(BusBootException::class, $ex);
        self::assertInstanceOf(BusInvariantException::class, $ex);
        self::assertStringContainsString('App\\Command\\PlaceOrder', $ex->getMessage());
    }
}
