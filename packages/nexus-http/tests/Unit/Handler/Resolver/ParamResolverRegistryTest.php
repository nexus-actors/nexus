<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\InvocationContext;
use Monadial\Nexus\Http\Handler\Resolver\ParamMetadata;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(ParamResolverRegistry::class)]
final class ParamResolverRegistryTest extends TestCase
{
    #[Test]
    public function first_non_null_resolver_wins(): void
    {
        $registry = (new ParamResolverRegistry())
            ->with($this->skipResolver())
            ->with($this->yesResolver('b'))
            ->with($this->yesResolver('c'));

        $metadata = $registry->compile($this->refOf('p'), $this->ctx());

        self::assertSame('b', $metadata->payload['tag']);
    }

    #[Test]
    public function with_returns_a_new_instance_appended(): void
    {
        $r1 = new ParamResolverRegistry();
        $r1->with($this->yesResolver('a'));   // ignored — return value discarded

        // r1 itself is still empty
        $this->expectException(UnresolvableParameterException::class);
        $r1->compile($this->refOf('p'), $this->ctx());
    }

    #[Test]
    public function with_override_prepends_so_user_wins(): void
    {
        $registry = (new ParamResolverRegistry())
            ->with($this->yesResolver('builtin'))
            ->withOverride($this->yesResolver('user'));

        $metadata = $registry->compile($this->refOf('p'), $this->ctx());

        self::assertSame('user', $metadata->payload['tag']);
    }

    #[Test]
    public function throws_when_no_resolver_claims_the_parameter(): void
    {
        $registry = new ParamResolverRegistry();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessageMatches('/\\$p/');

        $registry->compile($this->refOf('p'), $this->ctx());
    }

    private function ctx(): CompileContext
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new CompileContext(
            Scope::HttpRequest,
            'TestOwner',
            new ResolverServices(ResolvedActorTable::build([], $system, null)),
        );
    }

    private function refOf(string $name): ReflectionParameter
    {
        $fn = static function (string $p): void {};

        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function skipResolver(): ParamResolver
    {
        return new class implements ParamResolver {
            #[Override]
            public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
            {
                return null;
            }

            #[Override]
            public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
            {
                return null;
            }
        };
    }

    private function yesResolver(string $tag): ParamResolver
    {
        return new class ($tag) implements ParamResolver {
            public function __construct(private string $tag) {}

            #[Override]
            public function compile(ReflectionParameter $param, CompileContext $ctx): ?ParamMetadata
            {
                return new ParamMetadata($this, $param->getName(), null, ['tag' => $this->tag]);
            }

            #[Override]
            public function resolve(ParamMetadata $metadata, InvocationContext $ctx): mixed
            {
                /** @var string */
                return $metadata->payload['tag'];
            }
        };
    }
}
