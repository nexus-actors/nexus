<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticTokenAuthenticator::class)]
final class StaticTokenAuthenticatorTest extends TestCase
{
    #[Test]
    public function returns_principal_for_known_token(): void
    {
        $alice = new SimplePrincipal('alice');
        $auth = new StaticTokenAuthenticator(['k_alice' => $alice], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer k_alice');

        self::assertSame($alice, $auth->authenticate($req));
    }

    #[Test]
    public function returns_null_for_unknown_token(): void
    {
        $auth = new StaticTokenAuthenticator(['k_alice' => new SimplePrincipal('alice')], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer k_bob');

        self::assertNull($auth->authenticate($req));
    }

    #[Test]
    public function returns_null_when_extractor_finds_no_token(): void
    {
        $auth = new StaticTokenAuthenticator(['k_alice' => new SimplePrincipal('alice')], new BearerTokenExtractor());

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($auth->authenticate($req));
    }
}
