<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Testing;

use Monadial\Nexus\Symfony\Coroutine\CoroutineContext;

trait NexusTestTrait
{
    private function mockActor(string $name): MockActorRef
    {
        $mock = new MockActorRef();

        static::getContainer()->set("nexus.actor_ref.{$name}", $mock);

        return $mock;
    }

    private function swapCoroutineContext(): MockCoroutineContext
    {
        $mock = new MockCoroutineContext();

        static::getContainer()->set('nexus.coroutine_context', $mock);
        static::getContainer()->set(CoroutineContext::class, $mock);

        return $mock;
    }
}
