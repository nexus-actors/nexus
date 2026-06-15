<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit;

use Monadial\Nexus\Http\Auth\AuthChallenge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthChallenge::class)]
final class AuthChallengeTest extends TestCase
{
    #[Test]
    public function formats_a_bearer_challenge_with_realm(): void
    {
        $challenge = new AuthChallenge('Bearer', 'api');

        self::assertSame('Bearer realm="api"', $challenge->toHeader());
    }

    #[Test]
    public function omits_realm_when_null(): void
    {
        $challenge = new AuthChallenge('Bearer');

        self::assertSame('Bearer', $challenge->toHeader());
    }

    #[Test]
    public function appends_error_parameter_when_set(): void
    {
        $challenge = new AuthChallenge('Bearer', 'api', 'invalid_token');

        self::assertSame('Bearer realm="api", error="invalid_token"', $challenge->toHeader());
    }

    #[Test]
    public function escapes_double_quotes_in_realm(): void
    {
        $challenge = new AuthChallenge('Bearer', 'pro"d');

        self::assertSame('Bearer realm="pro\\"d"', $challenge->toHeader());
    }
}
