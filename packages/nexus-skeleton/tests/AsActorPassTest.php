<?php

declare(strict_types=1);

namespace App\Tests;

use App\DependencyInjection\AsActorPass;
use Monadial\Nexus\App\ActorRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AsActorPassTest extends TestCase
{
    #[Test]
    public function foldsTaggedServicesIntoTheRegistry(): void
    {
        $c = new ContainerBuilder();
        $c->register(ActorRegistry::class, ActorRegistry::class)->setPublic(true);
        $c->register('App\\Actor\\GreeterActor', 'App\\Actor\\GreeterActor')
            ->addTag('nexus.actor', ['name' => 'greeter'])
            ->setPublic(true)
            ->setShared(false);

        (new AsActorPass())->process($c);
        $c->compile();

        self::assertSame(
            ['greeter' => 'App\\Actor\\GreeterActor'],
            $c->get(ActorRegistry::class)->all(),
        );
    }
}
