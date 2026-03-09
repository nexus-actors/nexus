<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Generator\MessageGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageGenerator::class)]
final class MessageGeneratorTest extends TestCase
{
    private MessageGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MessageGenerator();
    }

    #[Test]
    public function generates_input_message(): void
    {
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [new ParameterDefinition('id', 'string', false)],
            returnType: '\\App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );

        $code = $this->generator->generateInput('App\\Generated\\Actor\\Product', $method);

        self::assertStringContainsString('readonly class GetProduct', $code);
        self::assertStringContainsString('public string $id', $code);
        self::assertStringContainsString("namespace App\\Generated\\Actor\\Product\\Message", $code);
    }

    #[Test]
    public function generates_response_message(): void
    {
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [],
            returnType: '\\App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );

        $code = $this->generator->generateResponse('App\\Generated\\Actor\\Product', $method);

        self::assertStringContainsString('readonly class GetProductResponse', $code);
        self::assertStringContainsString('public \\App\\Entity\\Product $result', $code);
    }

    #[Test]
    public function void_method_has_no_response(): void
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

        self::assertNull($this->generator->generateResponse('App\\Generated\\Actor\\Product', $method));
    }
}
