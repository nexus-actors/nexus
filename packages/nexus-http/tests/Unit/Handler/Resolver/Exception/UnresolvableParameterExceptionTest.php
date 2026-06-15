<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Exception;

use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(UnresolvableParameterException::class)]
final class UnresolvableParameterExceptionTest extends TestCase
{
    #[Test]
    public function message_includes_owner_param_name_and_actionable_hints(): void
    {
        // NOTE: CompileContext doesn't exist yet — lands in T3. SKIP this
        // test for now; un-skip after T3 (the dispatcher will re-run it).
        self::markTestSkipped('CompileContext lands in T3 — re-enabled there');

        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];
        $ctx = new CompileContext(
            Scope::HttpRequest,
            'Acme\OrderHandler',
            new ResolverServices(new ResolvedActorTable([], [])),
        );

        $exception = UnresolvableParameterException::forParameter($param, $ctx);

        self::assertStringContainsString('Acme\OrderHandler', $exception->getMessage());
        self::assertStringContainsString('$orderId', $exception->getMessage());
        self::assertStringContainsString('#[FromActor', $exception->getMessage());
        self::assertStringContainsString('#[FromService', $exception->getMessage());
        self::assertStringContainsString('paramResolver', $exception->getMessage());
    }
}
