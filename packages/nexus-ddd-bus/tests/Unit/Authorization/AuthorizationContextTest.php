<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Authorization;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationContext;
use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

#[CoversClass(AuthorizationContext::class)]
final class AuthorizationContextTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllThreeFields(): void
    {
        $headers = Headers::of(['nexus.principal-id' => 'user-1']);
        $envelope = new Envelope(new stdClass(), MessageMetadata::root($this->fixedClock()));
        $ctx = new AuthorizationContext(Option::none(), $headers, $envelope);

        self::assertTrue($ctx->principal->isNone());
        self::assertSame($headers, $ctx->headers);
        self::assertSame($envelope, $ctx->envelope);
    }

    #[Test]
    public function withPrincipalWrapsInOptionSomeAndLeavesOriginalUnchanged(): void
    {
        $principal = new class implements Principal {
            #[Override]
            public function id(): string
            {
                return 'user-42';
            }
        };
        $envelope = new Envelope(new stdClass(), MessageMetadata::root($this->fixedClock()));
        $ctx = new AuthorizationContext(Option::none(), Headers::empty(), $envelope);

        $next = $ctx->withPrincipal($principal);

        self::assertTrue($next->principal->isSome());
        self::assertSame($principal, $next->principal->get());
        self::assertTrue($ctx->principal->isNone());
    }

    #[Test]
    public function withPrincipalPreservesHeadersAndEnvelope(): void
    {
        $principal = new class implements Principal {
            #[Override]
            public function id(): string
            {
                return 'user-99';
            }
        };
        $headers = Headers::of(['x-trace' => 'abc']);
        $envelope = new Envelope(new stdClass(), MessageMetadata::root($this->fixedClock()));
        $ctx = new AuthorizationContext(Option::none(), $headers, $envelope);

        $next = $ctx->withPrincipal($principal);

        self::assertSame($headers, $next->headers);
        self::assertSame($envelope, $next->envelope);
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            #[Override]
            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
