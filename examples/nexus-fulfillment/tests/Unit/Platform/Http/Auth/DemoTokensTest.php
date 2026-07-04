<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Http\Auth;

use Monadial\Nexus\Example\Fulfillment\Platform\Http\Auth\DemoTokens;
use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoTokens::class)]
final class DemoTokensTest extends TestCase
{
    #[Test]
    public function returnsStaticTokenAuthenticator(): void
    {
        self::assertInstanceOf(StaticTokenAuthenticator::class, DemoTokens::authenticator());
    }

    #[Test]
    public function acmeOpsTokenMapsToOpsPrincipal(): void
    {
        $auth = DemoTokens::authenticator();
        $request = new ServerRequest('GET', '/', ['Authorization' => 'Bearer acme-ops-token']);
        $principal = $auth->authenticate($request);

        self::assertNotNull($principal);
        self::assertSame('ops@acme', $principal->id());
        self::assertTrue($principal->hasRole('ops'));
        self::assertSame('acme', $principal->claims()['tenant']);
    }

    #[Test]
    public function umbrellaOpsTokenMapsToDifferentTenant(): void
    {
        $auth = DemoTokens::authenticator();
        $request = new ServerRequest('GET', '/', ['Authorization' => 'Bearer umbrella-ops-token']);
        $principal = $auth->authenticate($request);

        self::assertNotNull($principal);
        self::assertSame('ops@umbrella', $principal->id());
        self::assertTrue($principal->hasRole('ops'));
        self::assertSame('umbrella', $principal->claims()['tenant']);
    }

    #[Test]
    public function unknownTokenReturnsNull(): void
    {
        $auth = DemoTokens::authenticator();
        $request = new ServerRequest('GET', '/', ['Authorization' => 'Bearer unknown-token-xyz']);

        self::assertNull($auth->authenticate($request));
    }
}
