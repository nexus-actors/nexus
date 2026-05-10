<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Validation;

use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationContext::class)]
final class ValidationContextTest extends TestCase
{
    #[Test]
    public function defaultProducesEmptyContext(): void
    {
        $ctx = ValidationContext::default();

        self::assertSame([], $ctx->groups);
        self::assertTrue($ctx->principal->isNone());
        self::assertSame([], $ctx->headers->values);
    }

    #[Test]
    public function withGroupsAttachesNewGroupsAndLeavesOriginalUnchanged(): void
    {
        $ctx = ValidationContext::default();

        $next = $ctx->withGroups(['Default', 'Strict']);

        self::assertSame(['Default', 'Strict'], $next->groups);
        self::assertSame([], $ctx->groups);
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
        $ctx = ValidationContext::default();

        $next = $ctx->withPrincipal($principal);

        self::assertTrue($next->principal->isSome());
        self::assertSame($principal, $next->principal->get());
        self::assertTrue($ctx->principal->isNone());
    }

    #[Test]
    public function withHeadersAttachesNewHeadersAndLeavesOriginalUnchanged(): void
    {
        $headers = Headers::of(['nexus.principal-id' => 'user-42']);
        $ctx = ValidationContext::default();

        $next = $ctx->withHeaders($headers);

        self::assertSame($headers, $next->headers);
        self::assertSame([], $ctx->headers->values);
    }
}
