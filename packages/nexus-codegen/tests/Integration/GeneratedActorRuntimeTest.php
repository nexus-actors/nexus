<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Integration;

use Monadial\Nexus\Codegen\Actorizer;
use Monadial\Nexus\Codegen\Tests\Fixture\Product;
use Monadial\Nexus\Codegen\Tests\Fixture\ProductService;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * End-to-end: generate actor files, load them, dispatch real messages.
 */
#[CoversNothing]
final class GeneratedActorRuntimeTest extends TestCase
{
    private static string $outputDir;

    /** @var class-string */
    private static string $actorClass;

    /** @var class-string */
    private static string $getProductClass;

    /** @var class-string */
    private static string $getProductResponseClass;

    /** @var class-string */
    private static string $deleteProductClass;

    #[Test]
    public function generated_actor_implements_actor_handler(): void
    {
        $actor = new self::$actorClass(new ProductService());

        self::assertInstanceOf(ActorHandler::class, $actor);
    }

    #[Test]
    public function generated_actor_dispatches_query_and_replies(): void
    {
        $actor = new self::$actorClass(new ProductService());

        $capturedReply = null;
        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())
            ->method('reply')
            ->with(self::callback(static function (object $msg) use (&$capturedReply): bool {
                $capturedReply = $msg;

                return true;
            }));

        $message = new self::$getProductClass('prod-1');
        $result = $actor->handle($ctx, $message);

        self::assertInstanceOf(SameBehavior::class, $result);
        self::assertInstanceOf(self::$getProductResponseClass, $capturedReply);

        /** @var object{result: Product} $capturedReply */
        self::assertSame('prod-1', $capturedReply->result->id);
        self::assertSame('Test', $capturedReply->result->name);
    }

    #[Test]
    public function generated_actor_dispatches_void_method_without_reply(): void
    {
        $actor = new self::$actorClass(new ProductService());

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::never())->method('reply');

        $message = new self::$deleteProductClass('prod-1');
        $result = $actor->handle($ctx, $message);

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function generated_actor_returns_unhandled_for_unknown_messages(): void
    {
        $actor = new self::$actorClass(new ProductService());

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::never())->method('reply');

        $result = $actor->handle($ctx, new stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $result);
    }

    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$outputDir = sys_get_temp_dir() . '/nexus-codegen-runtime-' . uniqid();
        mkdir(self::$outputDir, recursive: true);

        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        $actorizer = new Actorizer(outputBaseDir: self::$outputDir, loader: $loader);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $actorDir = self::$outputDir . '/Nexus/Codegen/Tests/Fixture/Generated';
        $messageDir = $actorDir . '/Message';

        // Load generated message classes before the actor class
        require_once $messageDir . '/GetProduct.php';
        require_once $messageDir . '/GetProductResponse.php';
        require_once $messageDir . '/CreateProduct.php';
        require_once $messageDir . '/CreateProductResponse.php';
        require_once $messageDir . '/DeleteProduct.php';
        require_once $actorDir . '/ProductServiceActor.php';

        $ns = 'Monadial\\Nexus\\Codegen\\Tests\\Fixture\\Generated';
        self::$actorClass = $ns . '\\ProductServiceActor';
        self::$getProductClass = $ns . '\\Message\\GetProduct';
        self::$getProductResponseClass = $ns . '\\Message\\GetProductResponse';
        self::$deleteProductClass = $ns . '\\Message\\DeleteProduct';
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . self::$outputDir);
    }
}
