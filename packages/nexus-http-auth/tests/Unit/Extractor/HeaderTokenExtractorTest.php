<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\HeaderTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderTokenExtractor::class)]
final class HeaderTokenExtractorTest extends TestCase
{
    #[Test]
    public function extracts_raw_header_value(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('X-Api-Key', 'k_live_abcdef');

        self::assertSame('k_live_abcdef', (new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }

    #[Test]
    public function returns_null_when_header_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull((new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }

    #[Test]
    public function returns_null_when_header_empty(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('X-Api-Key', '');

        self::assertNull((new HeaderTokenExtractor('X-Api-Key'))->extract($req));
    }
}
