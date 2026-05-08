<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MessagingCanaryTest extends TestCase
{
    #[Test]
    public function packageAutoloaderResolvesNamespace(): void
    {
        $namespace = 'Monadial\\Nexus\\Ddd\\Messaging\\';
        $autoload = require __DIR__ . '/../../../../vendor/composer/autoload_psr4.php';

        self::assertArrayHasKey($namespace, $autoload);
        self::assertNotSame([], $autoload[$namespace]);
    }
}
