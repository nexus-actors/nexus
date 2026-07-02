<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Authenticator\ChainAuthenticator;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

#[CoversClass(ChainAuthenticator::class)]
final class ChainAuthenticatorTest extends TestCase
{
    #[Test]
    public function returns_first_non_null_principal(): void
    {
        $alice = new SimplePrincipal('alice');
        $bob = new SimplePrincipal('bob');

        $chain = new ChainAuthenticator([
            new StubAuthenticator(null),
            new StubAuthenticator($alice),
            new StubAuthenticator($bob),
        ]);

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertSame($alice, $chain->authenticate($req));
    }

    #[Test]
    public function returns_null_when_all_return_null(): void
    {
        $chain = new ChainAuthenticator([new StubAuthenticator(null), new StubAuthenticator(null)]);

        $req = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNull($chain->authenticate($req));
    }

    #[Test]
    public function empty_chain_returns_null(): void
    {
        $chain = new ChainAuthenticator([]);

        self::assertNull($chain->authenticate((new Psr17Factory())->createServerRequest('GET', '/')));
    }

    #[Test]
    public function exceptions_from_inner_authenticators_propagate(): void
    {
        $chain = new ChainAuthenticator([
            new ThrowingAuthenticator(new RuntimeException('backend down')),
            new StubAuthenticator(new SimplePrincipal('alice')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backend down');

        $chain->authenticate((new Psr17Factory())->createServerRequest('GET', '/'));
    }
}

final readonly class StubAuthenticator implements Authenticator
{
    public function __construct(private ?Principal $principal) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return $this->principal;
    }
}

final readonly class ThrowingAuthenticator implements Authenticator
{
    public function __construct(private Throwable $exception) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        throw $this->exception;
    }
}
