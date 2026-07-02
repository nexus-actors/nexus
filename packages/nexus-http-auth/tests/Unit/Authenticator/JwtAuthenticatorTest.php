<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use DateInterval;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Tests\Support\Fixtures;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function explode;
use function implode;
use function preg_replace;
use function sleep;

#[CoversClass(JwtAuthenticator::class)]
final class JwtAuthenticatorTest extends TestCase
{
    #[Test]
    public function valid_token_yields_principal(): void
    {
        $alice = new SimplePrincipal('alice', scopes: ['orders.read', 'orders.write']);
        $token = Fixtures::tokenFor($alice);

        $auth = $this->makeAuth();
        $req = $this->reqWithToken($token->toString());

        $principal = $auth->authenticate($req);

        self::assertNotNull($principal);
        self::assertSame('alice', $principal->id());
        self::assertContains('orders.read', $principal->scopes());
    }

    #[Test]
    public function bad_signature_yields_null(): void
    {
        $alice = new SimplePrincipal('alice');
        $token = Fixtures::tokenFor($alice)->toString();

        // Tamper with the signature segment.
        $parts = explode('.', $token);
        $parts[2] = (string) preg_replace('/[A-Za-z]/', 'X', $parts[2]);
        $tampered = implode('.', $parts);

        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken($tampered)));
    }

    #[Test]
    public function expired_token_yields_null(): void
    {
        // Issue a token whose exp is BEFORE issued-at (immediately expired).
        // lcobucci/jwt's StrictValidAt rejects it.
        $expired = Fixtures::tokenFor(
            new SimplePrincipal('alice'),
            new DateInterval('PT0S'),
        );

        // Sleep one second to push past any clock leeway in StrictValidAt.
        sleep(1);

        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken($expired->toString())));
    }

    #[Test]
    public function malformed_token_yields_null(): void
    {
        $auth = $this->makeAuth();

        self::assertNull($auth->authenticate($this->reqWithToken('not-a-jwt')));
    }

    #[Test]
    public function no_token_yields_null(): void
    {
        $auth = $this->makeAuth();
        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($auth->authenticate($req));
    }

    #[Test]
    public function claims_mapper_receives_the_parsed_token_claims(): void
    {
        /** @var array<string, mixed> $captured */
        $captured = [];
        $auth = new JwtAuthenticator(
            Fixtures::hs256Config(),
            new BearerTokenExtractor(),
            static function ($token) use (&$captured): Principal {
                $captured = $token->claims()->all();

                return new SimplePrincipal((string) $token->claims()->get('sub'));
            },
        );

        $token = Fixtures::tokenFor(new SimplePrincipal('alice', roles: ['admin']));
        $auth->authenticate($this->reqWithToken($token->toString()));

        self::assertSame('alice', $captured['sub'] ?? null);
        self::assertSame(['admin'], $captured['roles'] ?? null);
    }

    private function makeAuth(): JwtAuthenticator
    {
        return new JwtAuthenticator(
            Fixtures::hs256Config(),
            new BearerTokenExtractor(),
            static fn($token) => new SimplePrincipal(
                (string) $token->claims()->get('sub'),
                scopes: explode(' ', (string) $token->claims()->get('scope', '')),
                claims: $token->claims()->all(),
            ),
        );
    }

    private function reqWithToken(string $token): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }
}
