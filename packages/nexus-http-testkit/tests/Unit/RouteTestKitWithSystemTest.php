<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Extract\StringSegment;
use Monadial\Nexus\Http\TestKit\RouteTestKit;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;

final readonly class EchoQuery
{
    public function __construct(public string $name) {}
}

final readonly class EchoReply
{
    public function __construct(public string $name) {}
}

#[CoversClass(RouteTestKit::class)]
final class RouteTestKitWithSystemTest extends TestCase
{
    #[Test]
    public function ask_via_real_step_runtime_round_trips(): void
    {
        $runtime = new StepRuntime();
        $system = ActorSystem::create('test', $runtime);

        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof EchoQuery) {
                $sender = $ctx->sender();

                if ($sender !== null) {
                    $sender->tell(new EchoReply($msg->name));
                }
            }

            return Behavior::same();
        });
        $system->spawn(Props::fromBehavior($behavior), 'echo');

        $route = get(static fn() => path(
            'echo',
            StringSegment::class,
            static fn(string $name) => complete(static fn($ctx) => $ctx->ask('echo', new EchoQuery($name))),
        ));

        $result = RouteTestKit::route($route)
            ->withSystem($system)
            ->get('/echo/world')
            ->run();

        self::assertSame(200, $result->status());
    }
}
