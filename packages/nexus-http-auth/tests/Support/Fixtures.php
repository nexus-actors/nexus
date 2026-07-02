<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Monadial\Nexus\Http\Auth\Principal;
use RuntimeException;

use function implode;

/**
 * @psalm-api
 *
 * Test fixtures shared between JwtAuthenticatorTest and Integration tests.
 * Provides a stable HS256 keypair and a token-builder factory.
 */
final class Fixtures
{
    public static function hs256Config(): Configuration
    {
        return Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText('test-secret-at-least-256-bits-aaaaaaaaaaaa'),
        );
    }

    /**
     * @param non-empty-string|null $issuer
     * @param non-empty-string|null $audience
     */
    public static function tokenFor(
        Principal $principal,
        ?DateInterval $expiresIn = null,
        ?string $issuer = null,
        ?string $audience = null,
    ): Plain {
        $config = self::hs256Config();
        $now = new DateTimeImmutable();
        $exp = $expiresIn === null
            ? $now->modify('+1 hour')
            : $now->add($expiresIn);

        $id = $principal->id();

        if ($id === '') {
            throw new RuntimeException('Principal id must be non-empty for token issuance.');
        }

        $builder = $config->builder()
            ->relatedTo($id)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($exp)
            ->withClaim('roles', $principal->roles())
            ->withClaim('scope', implode(' ', $principal->scopes()));

        if ($issuer !== null) {
            $builder = $builder->issuedBy($issuer);
        }

        if ($audience !== null) {
            $builder = $builder->permittedFor($audience);
        }

        $token = $builder->getToken($config->signer(), $config->signingKey());

        if (!$token instanceof Plain) {
            throw new RuntimeException('Expected Plain token, got ' . $token::class);
        }

        return $token;
    }
}
