<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Generator\AsyncInterfaceGenerator;
use Monadial\Nexus\Core\Supervision\StrategyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsyncInterfaceGenerator::class)]
final class AsyncInterfaceGeneratorTest extends TestCase
{
    #[Test]
    public function generates_async_interface_extending_original(): void
    {
        $definition = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition(
                    'getProduct',
                    'GetProduct',
                    [new ParameterDefinition('id', 'string', false)],
                    '\\App\\Entity\\Product',
                    false,
                    false,
                    false,
                ),
                new MethodDefinition(
                    'deleteProduct',
                    'DeleteProduct',
                    [new ParameterDefinition('id', 'string', false)],
                    null,
                    true,
                    true,
                    false,
                ),
            ],
            async: true,
            timeout: 5,
            supervision: StrategyType::OneForOne,
            reset: null,
        );

        $code = (new AsyncInterfaceGenerator())->generate($definition);

        self::assertStringContainsString('interface ProductServiceAsyncInterface', $code);
        self::assertStringContainsString('extends', $code);
        self::assertStringContainsString('getProductAsync', $code);
        // void methods have no async variant
        self::assertStringNotContainsString('deleteProductAsync', $code);
    }

    #[Test]
    public function no_async_methods_excluded(): void
    {
        $definition = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition('getProduct', 'GetProduct', [], '\\App\\Entity\\Product', false, false, true),
            ],
            async: true,
            timeout: 5,
            supervision: StrategyType::OneForOne,
            reset: null,
        );

        $code = (new AsyncInterfaceGenerator())->generate($definition);

        self::assertStringNotContainsString('getProductAsync', $code);
    }
}
