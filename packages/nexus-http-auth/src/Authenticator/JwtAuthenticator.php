<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_map;

/**
 * @psalm-api
 *
 * Verifies a JWT (HS256/RS256/ES256/EdDSA per the configured signer) and
 * delegates Principal construction to the claims-mapper closure.
 *
 *   $jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
 *   $auth = new JwtAuthenticator(
 *       $jwt,
 *       new BearerTokenExtractor(),
 *       fn (Plain $t) => new SimplePrincipal(
 *           id: (string) $t->claims()->get('sub'),
 *           scopes: explode(' ', (string) $t->claims()->get('scope', '')),
 *       ),
 *   );
 *
 * Failures (bad signature, expired, malformed) return null — never throw.
 * The reason is logged via PSR-3 at info/debug, never disclosed on the wire.
 */
final class JwtAuthenticator implements Authenticator
{
    private readonly TokenExtractor $extractor;

    private readonly LoggerInterface $logger;

    private readonly ClockInterface $clock;

    /** @var Closure(Plain): ?Principal */
    private readonly Closure $claimsMapper;

    /**
     * @param Closure(Plain): ?Principal $claimsMapper
     */
    public function __construct(
        private readonly Configuration $jwt,
        ?TokenExtractor $extractor = null,
        ?Closure $claimsMapper = null,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->extractor = $extractor ?? new BearerTokenExtractor();
        $this->claimsMapper = $claimsMapper ?? static fn(Plain $_token): ?Principal => null;
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new class implements ClockInterface {
            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        };
    }

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $token = $this->extractor->extract($request);

        if ($token === null || $token === '') {
            return null;
        }

        try {
            $parsed = $this->jwt->parser()->parse($token);
        } catch (Throwable $e) {
            $this->logger->debug('auth.token.malformed', ['error' => $e::class]);

            return null;
        }

        if (!$parsed instanceof Plain) {
            $this->logger->info('auth.token.unsupportedFormat');

            return null;
        }

        try {
            $this->jwt->validator()->assert(
                $parsed,
                new SignedWith($this->jwt->signer(), $this->jwt->verificationKey()),
                new StrictValidAt($this->clock),
            );
        } catch (RequiredConstraintsViolated $e) {
            $this->logger->info('auth.token.constraintsViolated', [
                'errors' => array_map(static fn($v) => $v->getMessage(), $e->violations()),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->logger->info('auth.token.validationFailed', ['error' => $e::class]);

            return null;
        }

        return ($this->claimsMapper)($parsed);
    }
}
