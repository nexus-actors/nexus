<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Generator\ProxyGenerator;
use Monadial\Nexus\Core\Supervision\StrategyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProxyGenerator::class)]
final class ProxyGeneratorTest extends TestCase
{
    private function makeDefinition(): ServiceDefinition
    {
        return new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition('getProduct', 'GetProduct', [new ParameterDefinition('id', 'string', false)], '\\App\\Entity\\Product', false, false, false),
                new MethodDefinition('deleteProduct', 'DeleteProduct', [new ParameterDefinition('id', 'string', false)], null, true, true, false),
            ],
            async: true,
            timeout: 5,
            supervision: StrategyType::OneForOne,
            reset: null,
        );
    }

    #[Test]
    public function generates_proxy_class(): void
    {
        $code = (new ProxyGenerator())->generate($this->makeDefinition());

        self::assertStringContainsString('final class ProductServiceActorProxy', $code);
        self::assertStringContainsString('ActorRef', $code);
        self::assertStringContainsString('Duration', $code);
    }

    #[Test]
    public function sync_method_uses_ask(): void
    {
        $code = (new ProxyGenerator())->generate($this->makeDefinition());

        self::assertStringContainsString('getProduct(', $code);
        self::assertStringContainsString('ask(', $code);
        self::assertStringContainsString('->result', $code);
    }

    #[Test]
    public function void_method_uses_tell(): void
    {
        $code = (new ProxyGenerator())->generate($this->makeDefinition());

        self::assertStringContainsString('deleteProduct(', $code);
        self::assertStringContainsString('tell(', $code);
    }

    #[Test]
    public function async_method_returns_future(): void
    {
        $code = (new ProxyGenerator())->generate($this->makeDefinition());

        self::assertStringContainsString('getProductAsync(', $code);
        self::assertStringContainsString('Future', $code);
    }
}
