<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Extractor;

use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerTokenExtractor::class)]
final class BearerTokenExtractorTest extends TestCase
{
    #[Test]
    public function extracts_token_from_authorization_header(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer abc.def.ghi');

        self::assertSame('abc.def.ghi', (new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function returns_null_when_header_absent(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull((new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function returns_null_when_scheme_is_not_bearer(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Basic dXNlcjpwYXNz');

        self::assertNull((new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function tolerates_extra_whitespace_after_bearer(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer   token-with-spaces');

        self::assertSame('token-with-spaces', (new BearerTokenExtractor())->extract($req));
    }

    #[Test]
    public function case_insensitive_scheme_match(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'bearer token');

        self::assertSame('token', (new BearerTokenExtractor())->extract($req));
    }
}
