<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Authorization;

use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationContext;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationDecider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class AuthorizationDeciderInterfaceTest extends TestCase
{
    #[Test]
    public function decideAcceptsPolicySubjectAndContextAndReturnsVoid(): void
    {
        $reflection = new ReflectionClass(AuthorizationDecider::class);
        $method = $reflection->getMethod('decide');
        $params = $method->getParameters();

        self::assertCount(3, $params);

        self::assertSame('policy', $params[0]->getName());
        $policyType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $policyType);
        self::assertSame('string', $policyType->getName());

        self::assertSame('subject', $params[1]->getName());
        $subjectType = $params[1]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $subjectType);
        self::assertSame('mixed', $subjectType->getName());

        self::assertSame('context', $params[2]->getName());
        $contextType = $params[2]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $contextType);
        self::assertSame(AuthorizationContext::class, $contextType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function interfaceExposesOnlyDecide(): void
    {
        $reflection = new ReflectionClass(AuthorizationDecider::class);
        $methods = array_map(static fn($m): string => $m->getName(), $reflection->getMethods());

        self::assertSame(['decide'], $methods);
    }
}
