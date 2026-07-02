<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Swoole\Tests\Unit;

use Monadial\Nexus\Observability\Swoole\SwooleContextRegistrar;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleContextRegistrar::class)]
final class SwooleContextRegistrarTest extends TestCase
{
    #[Test]
    public function installsSwooleContextStorageIdempotently(): void
    {
        $seen = [];

        run(static function () use (&$seen): void {
            SwooleContextRegistrar::install();
            $first = Context::storage();
            SwooleContextRegistrar::install(); // idempotent
            $second = Context::storage();

            $seen['first'] = $first instanceof SwooleContextStorage;
            $seen['same'] = $first === $second;
        });

        self::assertTrue($seen['first'], 'storage should be SwooleContextStorage after install');
        self::assertTrue($seen['same'], 'install should be idempotent (same storage instance)');
    }
}
