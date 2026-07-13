<?php

declare(strict_types=1);

namespace App\Tests;

use App\Actor\ExampleActor;
use App\Message\Ping;
use App\Message\Pong;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ExampleActor replies with Pong when sent a Ping.
 * Uses StepRuntime for deterministic, tick-by-tick execution.
 */
final class ExampleActorTest extends TestCase
{
    #[Test]
    public function pingProducesPong(): void
    {
        $runtime = new \Monadial\Nexus\Runtime\Step\StepRuntime();
        $system = ActorSystem::create('test', $runtime);

        $received = [];

        $probe = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function ($ctx, object $msg) use (&$received): Behavior {
                        $received[] = $msg;

                        return Behavior::same();
                    },
                ),
            ),
            'probe',
        );

        $example = $system->spawn(
            Props::fromBehavior(ExampleActor::behavior()),
            'example',
        );

        $example->tell(new Ping($probe));

        $runtime->drain();

        self::assertCount(1, $received);
        self::assertInstanceOf(Pong::class, $received[0]);
    }
}
