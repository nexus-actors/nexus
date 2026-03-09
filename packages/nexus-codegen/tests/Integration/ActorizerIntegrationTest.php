<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Integration;

use Monadial\Nexus\Codegen\Actorizer;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ActorizerIntegrationTest extends TestCase
{
    private string $outputDir;

    #[Test]
    public function generates_all_files_for_async_service(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $this->outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        // Fixture namespace: Monadial\Nexus\Codegen\Tests\Fixture\Generated
        // → strips first segment → Nexus/Codegen/Tests/Fixture/Generated
        $actorDir = $this->outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';
        $messageDir = $actorDir . '/Message';

        self::assertDirectoryExists($actorDir);
        self::assertDirectoryExists($messageDir);

        $expectedFiles = [
            $messageDir . '/GetProduct.php',
            $messageDir . '/GetProductResponse.php',
            $messageDir . '/CreateProduct.php',
            $messageDir . '/CreateProductResponse.php',
            $messageDir . '/DeleteProduct.php',
            $actorDir . '/ProductServiceActor.php',
            $actorDir . '/ProductServiceAsyncInterface.php',
            $actorDir . '/ProductServiceActorProxy.php',
        ];

        foreach ($expectedFiles as $file) {
            self::assertFileExists($file);
        }

        self::assertFileDoesNotExist($messageDir . '/DeleteProductResponse.php');
    }

    #[Test]
    public function generated_files_are_valid_php(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $this->outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $actorDir = $this->outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';
        $messageDir = $actorDir . '/Message';

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $globbed = glob($messageDir . '/*.php');
        $files = $globbed !== false
            ? $globbed
            : [];
        $files[] = $actorDir . '/ProductServiceActor.php';
        $files[] = $actorDir . '/ProductServiceAsyncInterface.php';
        $files[] = $actorDir . '/ProductServiceActorProxy.php';

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertNotFalse($source, "Cannot read {$file}");

            $ast = $parser->parse($source);
            self::assertNotNull($ast, "Parse failed for {$file}");
            self::assertNotEmpty($traverser->traverse($ast), "No nodes in {$file}");
        }
    }

    #[Test]
    public function message_classes_have_correct_namespace_and_structure(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $this->outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $messageDir = $this->outputDir . '/Nexus/Codegen/Tests/Fixture/Generated/Message';

        $getProduct = (string) file_get_contents($messageDir . '/GetProduct.php');
        self::assertStringContainsString(
            'namespace Monadial\\Nexus\\Codegen\\Tests\\Fixture\\Generated\\Message',
            $getProduct,
        );
        self::assertStringContainsString('final readonly class GetProduct', $getProduct);
        self::assertStringContainsString('string $id', $getProduct);

        $getProductResponse = (string) file_get_contents($messageDir . '/GetProductResponse.php');
        self::assertStringContainsString('final readonly class GetProductResponse', $getProductResponse);

        $deleteProduct = (string) file_get_contents($messageDir . '/DeleteProduct.php');
        self::assertStringContainsString('final readonly class DeleteProduct', $deleteProduct);
        self::assertStringContainsString('string $id', $deleteProduct);
    }

    #[Test]
    public function actor_class_dispatches_all_methods(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $this->outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $actorDir = $this->outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';
        $actorSource = (string) file_get_contents($actorDir . '/ProductServiceActor.php');

        self::assertStringContainsString('final class ProductServiceActor', $actorSource);
        self::assertStringContainsString('Message\\GetProduct', $actorSource);
        self::assertStringContainsString('Message\\CreateProduct', $actorSource);
        self::assertStringContainsString('Message\\DeleteProduct', $actorSource);
    }

    #[Test]
    public function proxy_implements_async_interface(): void
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $this->outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $actorDir = $this->outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';

        $asyncInterface = (string) file_get_contents($actorDir . '/ProductServiceAsyncInterface.php');
        self::assertStringContainsString('interface ProductServiceAsyncInterface', $asyncInterface);
        self::assertStringContainsString('getProductAsync', $asyncInterface);
        self::assertStringContainsString('createProductAsync', $asyncInterface);

        $proxy = (string) file_get_contents($actorDir . '/ProductServiceActorProxy.php');
        self::assertStringContainsString('final class ProductServiceActorProxy', $proxy);
        self::assertStringContainsString('ProductServiceAsyncInterface', $proxy);
        self::assertStringContainsString('getProductAsync', $proxy);
        self::assertStringContainsString('createProductAsync', $proxy);
    }

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/nexus-codegen-integration-' . uniqid();
        mkdir($this->outputDir, recursive: true);
    }

    protected function tearDown(): void
    {
        exec("rm -rf {$this->outputDir}");
    }
}
