<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit;

use Monadial\Nexus\Codegen\Actorizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Actorizer::class)]
final class ActorizerTest extends TestCase
{
    #[Test]
    public function actorizes_fixture_service_and_writes_files(): void
    {
        $outputDir = sys_get_temp_dir() . '/nexus-codegen-test-' . uniqid();
        mkdir($outputDir, recursive: true);

        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: $outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        // fixture namespace: Monadial\Nexus\Codegen\Tests\Fixture\Generated → Nexus/Codegen/Tests/Fixture/Generated
        $actorDir = $outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';
        $messageDir = $actorDir . '/Message';

        self::assertFileExists($messageDir . '/GetProduct.php');
        self::assertFileExists($messageDir . '/GetProductResponse.php');
        self::assertFileExists($messageDir . '/CreateProduct.php');
        self::assertFileExists($messageDir . '/CreateProductResponse.php');
        self::assertFileExists($messageDir . '/DeleteProduct.php');
        self::assertFileDoesNotExist($messageDir . '/DeleteProductResponse.php');

        self::assertFileExists($actorDir . '/ProductServiceActor.php');
        self::assertFileExists($actorDir . '/ProductServiceAsyncInterface.php');
        self::assertFileExists($actorDir . '/ProductServiceActorProxy.php');

        exec("rm -rf {$outputDir}");
    }
}
