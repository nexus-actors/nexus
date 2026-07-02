<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\CookieTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CookieTokenExtractor::class)]
final class CookieTokenExtractorTest extends TestCase
{
    #[Test]
    public function reads_cookie_by_name(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withCookieParams(['session' => 'abc.def.ghi']);

        self::assertSame('abc.def.ghi', (new CookieTokenExtractor('session'))->extract($req));
    }

    #[Test]
    public function returns_null_when_cookie_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withCookieParams(['other' => 'x']);

        self::assertNull((new CookieTokenExtractor('session'))->extract($req));
    }
}
