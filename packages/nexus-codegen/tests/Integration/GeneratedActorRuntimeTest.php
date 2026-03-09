<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Integration;

use Monadial\Nexus\Codegen\Actorizer;
use Monadial\Nexus\Codegen\Tests\Fixture\Product;
use Monadial\Nexus\Codegen\Tests\Fixture\ProductService;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
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

    #[Test]
    public function generated_actor_answers_query_via_ask_in_actor_system(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('codegen-query-test', $runtime);

        $actorClass = self::$actorClass;
        $ref = $system->spawn(Props::fromFactory(static fn() => new $actorClass(new ProductService())), 'product');

        /** @var object|null $result */
        $result = null;
        $getProductClass = self::$getProductClass;

        $runtime->spawn(static function () use ($ref, $getProductClass, &$result): void {
            $result = $ref->ask(new $getProductClass('item-42'), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertInstanceOf(self::$getProductResponseClass, $result);

        /** @var object{result: Product} $result */
        self::assertSame('item-42', $result->result->id);
        self::assertSame('Test', $result->result->name);
        self::assertSame(9.99, $result->result->price);
    }

    #[Test]
    public function generated_actor_processes_void_method_via_tell_in_actor_system(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('codegen-void-test', $runtime);

        $actorClass = self::$actorClass;
        $ref = $system->spawn(Props::fromFactory(static fn() => new $actorClass(new ProductService())), 'product');

        $deleteProductClass = self::$deleteProductClass;
        $ref->tell(new $deleteProductClass('item-1'));

        // Use ask to confirm the actor processed the tell (actor replies to subsequent ask)
        $getProductClass = self::$getProductClass;

        /** @var object|null $result */
        $result = null;

        $runtime->spawn(static function () use ($ref, $getProductClass, &$result): void {
            $result = $ref->ask(new $getProductClass('after-delete'), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // Actor responded to query after processing void message — still running
        self::assertInstanceOf(self::$getProductResponseClass, $result);
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
