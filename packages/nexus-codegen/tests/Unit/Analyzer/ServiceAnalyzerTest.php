<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Analyzer;

use Monadial\Nexus\Codegen\Analyzer\ServiceAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceAnalyzer::class)]
final class ServiceAnalyzerTest extends TestCase
{
    private ServiceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ServiceAnalyzer();
    }

    #[Test]
    public function analyzes_service_class_into_definition(): void
    {
        $file = __DIR__ . '/../../Fixture/ProductService.php';

        $definition = $this->analyzer->analyze($file);

        self::assertSame('Monadial\\Nexus\\Codegen\\Tests\\Fixture\\ProductService', $definition->className);
        self::assertSame('Monadial\\Nexus\\Codegen\\Tests\\Fixture\\ProductServiceInterface', $definition->interfaceName);
        self::assertSame('Product', $definition->shortName);
        self::assertCount(3, $definition->methods);
    }

    #[Test]
    public function extracts_method_signatures(): void
    {
        $definition = $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductService.php');

        $get = $definition->methods[0];
        self::assertSame('getProduct', $get->name);
        self::assertSame('GetProduct', $get->pascalName);
        self::assertFalse($get->isVoid);
        self::assertFalse($get->mutates);
        self::assertCount(1, $get->parameters);
        self::assertSame('id', $get->parameters[0]->name);
        self::assertSame('string', $get->parameters[0]->type);
    }

    #[Test]
    public function detects_void_and_mutates(): void
    {
        $definition = $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductService.php');

        $delete = $definition->methods[2];
        self::assertSame('deleteProduct', $delete->name);
        self::assertTrue($delete->isVoid);
        self::assertTrue($delete->mutates);
    }

    #[Test]
    public function throws_when_no_actorize_attribute(): void
    {
        $this->expectException(\Monadial\Nexus\Codegen\Analyzer\AnalysisException::class);

        $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductServiceInterface.php');
    }
}
