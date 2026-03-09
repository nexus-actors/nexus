<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Generator\ActorGenerator;
use Monadial\Nexus\Core\Supervision\StrategyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorGenerator::class)]
final class ActorGeneratorTest extends TestCase
{
    #[Test]
    public function generates_actor_class(): void
    {
        $definition = $this->makeDefinition();
        $generator  = new ActorGenerator();

        $code = $generator->generate($definition);

        self::assertStringContainsString('final class ProductServiceActor implements ActorHandler', $code);
        self::assertStringContainsString('ProductServiceInterface $service', $code);
        self::assertStringContainsString('$message instanceof Message\GetProduct', $code);
        self::assertStringContainsString('$message instanceof Message\DeleteProduct', $code);
        self::assertStringContainsString('$ctx->reply(new Message\\GetProductResponse', $code);
        self::assertStringContainsString('resetIfNeeded', $code);
    }

    #[Test]
    public function void_handler_has_no_reply(): void
    {
        $definition = $this->makeDefinition();
        $generator  = new ActorGenerator();

        $code = $generator->generate($definition);

        self::assertStringNotContainsString('DeleteProductResponse', $code);
        self::assertStringContainsString('$this->service->deleteProduct(', $code);
    }

    private function makeDefinition(): ServiceDefinition
    {
        return new ServiceDefinition(
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
    }
}
