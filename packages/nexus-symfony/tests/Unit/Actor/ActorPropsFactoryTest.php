<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(ActorPropsFactory::class)]
final class ActorPropsFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsPropsInstance(): void
    {
        $actor = new readonly class implements ActorHandler {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($actor);

        $factory = new ActorPropsFactory($container, $actor::class);

        self::assertInstanceOf(Props::class, $factory->create());
    }
}
