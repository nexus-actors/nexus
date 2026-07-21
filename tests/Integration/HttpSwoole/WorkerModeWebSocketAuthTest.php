<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Authenticator\JwtAuthenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Resolver\FromPrincipalResolver;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WsApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

use function Co\run;
use function explode;
use function is_string;

/** Secure echo handler: requires the `chat` scope and replies with the principal id. */
#[RequiresScope('chat')]
final class SecureWhoAmIHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext]
        private readonly WebSocketContext $ctx,
        #[FromPrincipal]
        private readonly Principal $principal,
    ) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('principal:' . $this->principal->id());
    }
}

/** Open route without auth attributes — stays reachable anonymously. */
final class PublicEchoHandler extends WebSocketHandler
{
    public function __construct(#[FromContext] private readonly WebSocketContext $ctx) {}

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('public:' . $frame->text);
    }
}

/**
 * SEC-001: WebSocket upgrades are authorized BEFORE the 101 switch. Missing,
 * invalid, expired, and under-scoped tokens must be rejected with plain HTTP
 * status codes on the not-yet-upgraded connection; a valid scoped token must
 * upgrade and resolve the SAME principal after the upgrade via
 * #[FromPrincipal].
 */
#[CoversNothing]
final class WorkerModeWebSocketAuthTest extends TestCase
{
    private const string SECRET = 'sec-001-integration-test-secret-0123456789abcdef';

    private static ?ForkedSwooleServerFixture $fixture = null;

    private static int $port = 0;

    #[Test]
    public function missing_token_is_rejected_with_401_before_upgrade(): void
    {
        self::assertSame(401, $this->upgradeStatus('/ws/secure', null));
    }

    #[Test]
    public function invalid_token_is_rejected_with_401_before_upgrade(): void
    {
        self::assertSame(401, $this->upgradeStatus('/ws/secure', 'not-a-jwt'));
    }

    #[Test]
    public function expired_token_is_rejected_with_401_before_upgrade(): void
    {
        $token = $this->token('alice', 'chat', expiresIn: '-1 hour');

        self::assertSame(401, $this->upgradeStatus('/ws/secure', $token));
    }

    #[Test]
    public function token_without_required_scope_is_rejected_with_403_before_upgrade(): void
    {
        $token = $this->token('alice', 'other-scope');

        self::assertSame(403, $this->upgradeStatus('/ws/secure', $token));
    }

    #[Test]
    public function scoped_token_upgrades_and_resolves_the_principal_after_upgrade(): void
    {
        $token = $this->token('alice', 'chat admin');
        $reply = null;

        run(static function () use ($token, &$reply): void {
            $client = new Client('127.0.0.1', self::$port);
            $client->setHeaders(['Authorization' => 'Bearer ' . $token]);

            if ($client->upgrade('/ws/secure')) {
                $client->push('whoami');
                $frame = $client->recv(2.0);
                $reply = $frame instanceof Frame
                    ? $frame->data
                    : null;
            }

            $client->close();
        });

        self::assertSame('principal:alice', $reply);
    }

    #[Test]
    public function route_without_auth_attributes_stays_reachable_anonymously(): void
    {
        $reply = null;

        run(static function () use (&$reply): void {
            $client = new Client('127.0.0.1', self::$port);

            if ($client->upgrade('/ws/public')) {
                $client->push('hi');
                $frame = $client->recv(2.0);
                $reply = $frame instanceof Frame
                    ? $frame->data
                    : null;
            }

            $client->close();
        });

        self::assertSame('public:hi', $reply);
    }

    #[Test]
    public function unmatched_path_is_rejected_with_404_before_upgrade(): void
    {
        self::assertSame(404, $this->upgradeStatus('/ws/nope', null));
    }

    public static function setUpBeforeClass(): void
    {
        self::$port = ForkedSwooleServerFixture::findFreePort();
        self::$fixture = new ForkedSwooleServerFixture('127.0.0.1', self::$port);

        $port = self::$port;
        self::$fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false)
                    ->enableWebSocket(true),
                factory: static function (ActorSystem $system): CompiledApplication {
                    $jwt = Configuration::forSymmetricSigner(
                        new Sha256(),
                        InMemory::plainText(self::SECRET),
                    );
                    $authenticator = new JwtAuthenticator(
                        $jwt,
                        claimsMapper: static function (Plain $token): ?Principal {
                            $sub = $token->claims()->get('sub');
                            $scope = $token->claims()->get('scope', '');

                            if (!is_string($sub) || $sub === '') {
                                return null;
                            }

                            return new SimplePrincipal(
                                id: $sub,
                                scopes: is_string($scope) && $scope !== ''
                                    ? explode(' ', $scope)
                                    : [],
                            );
                        },
                    );

                    return WsApplication::create($system)
                        ->paramResolver(new FromPrincipalResolver())
                        ->wsMiddleware(new AuthenticationMiddleware($authenticator))
                        ->wsMiddleware(new AuthorizationMiddleware())
                        ->ws('/ws/secure', SecureWhoAmIHandler::class)
                        ->ws('/ws/public', PublicEchoHandler::class)
                        ->compile();
                },
            );
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->shutdown();
        self::$fixture = null;
    }

    /**
     * The HTTP status the client observed for the upgrade request. A granted
     * upgrade reports 101; a pre-upgrade rejection reports the gate's HTTP
     * status (Swoole's client returns true from upgrade() even for rejected
     * handshakes, so the status line is the authoritative signal).
     */
    private function upgradeStatus(string $path, ?string $token): int
    {
        $status = 0;

        run(static function () use ($path, $token, &$status): void {
            $client = new Client('127.0.0.1', self::$port);

            if ($token !== null) {
                $client->setHeaders(['Authorization' => 'Bearer ' . $token]);
            }

            (void) $client->upgrade($path);
            $status = $client->statusCode;
            $client->close();
        });

        return $status;
    }

    private function token(string $sub, string $scope, string $expiresIn = '+1 hour'): string
    {
        $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $now = new DateTimeImmutable();

        return $jwt->builder()
            ->issuedAt($now->modify('-1 minute'))
            ->canOnlyBeUsedAfter($now->modify('-1 minute'))
            ->expiresAt($now->modify($expiresIn))
            ->relatedTo($sub)
            ->withClaim('scope', $scope)
            ->getToken($jwt->signer(), $jwt->signingKey())
            ->toString();
    }
}
