<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Exception;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
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
        $fn = static function (string $orderId): void {};
        $param = (new ReflectionFunction($fn))->getParameters()[0];
        $system = ActorSystem::create('test', new TestRuntime());
        $ctx = new CompileContext(
            Scope::HttpRequest,
            'Acme\OrderHandler',
            new ResolverServices(ResolvedActorTable::build([], $system, null)),
        );

        $exception = UnresolvableParameterException::forParameter($param, $ctx);

        self::assertStringContainsString('Acme\OrderHandler', $exception->getMessage());
        self::assertStringContainsString('$orderId', $exception->getMessage());
        self::assertStringContainsString('#[FromActor', $exception->getMessage());
        self::assertStringContainsString('#[FromService', $exception->getMessage());
        self::assertStringContainsString('paramResolver', $exception->getMessage());
    }
}
