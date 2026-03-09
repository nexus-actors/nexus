<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Definition;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceDefinition::class)]
#[CoversClass(MethodDefinition::class)]
#[CoversClass(ParameterDefinition::class)]
final class ServiceDefinitionTest extends TestCase
{
    #[Test]
    public function service_definition_holds_expected_values(): void
    {
        $param = new ParameterDefinition('id', 'string', false);
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [$param],
            returnType: 'App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );
        $service = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [$method],
            async: true,
            timeout: 5,
            supervision: 'one-for-one',
            reset: null,
        );

        self::assertSame('App\\Service\\ProductService', $service->className);
        self::assertSame('Product', $service->shortName);
        self::assertSame([$method], $service->methods);
        self::assertFalse($method->isVoid);
        self::assertSame('string', $param->type);
    }

    #[Test]
    public function method_definition_identifies_void(): void
    {
        $method = new MethodDefinition(
            name: 'deleteProduct',
            pascalName: 'DeleteProduct',
            parameters: [new ParameterDefinition('id', 'string', false)],
            returnType: null,
            isVoid: true,
            mutates: true,
            noAsync: false,
        );

        self::assertTrue($method->isVoid);
        self::assertNull($method->returnType);
    }
}
