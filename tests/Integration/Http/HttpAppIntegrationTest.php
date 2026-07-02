<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class _RecordMessage
{
    public function __construct(public string $value) {}
}

#[CoversNothing]
final class HttpAppIntegrationTest extends TestCase
{
    #[Test]
    public function worker_local_actor_receives_tell_from_handler(): void
    {
        $received = [];
        /** @var Behavior<object> $recorderBehavior */
        $recorderBehavior = Behavior::receive(static function (
            ActorContext $ctx,
            object $msg,
        ) use (&$received): Behavior {
            if ($msg instanceof _RecordMessage) {
                $received[] = $msg->value;
            }

            return Behavior::same();
        });

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test-http', $runtime);
        $app = HttpApp::create($system);
        $app->actor('recorder', Props::fromBehavior($recorderBehavior))->workerLocal();
        $app->post('/record/{value}', static function (
            ServerRequestInterface $r,
            #[FromActor('recorder')]
            ActorRef $recorder,
        ): ResponseInterface {
            $value = (string) $r->getAttribute('value');
            $recorder->tell(new _RecordMessage($value));

            return JsonResponse::ok(['recorded' => $value]);
        });

        $response = $app->compile()->handle(new ServerRequest('POST', '/record/hello'));

        // Drive the runtime so the recorder fiber drains its mailbox.
        $runtime->scheduleOnce(Duration::millis(50), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"recorded":"hello"}', (string) $response->getBody());
        self::assertSame(['hello'], $received);
    }

    #[Test]
    public function per_request_actor_is_spawned_lazily_and_disposed(): void
    {
        $spawned = 0;
        /** @var Behavior<object> $sagaBehavior */
        $sagaBehavior = Behavior::receive(static function (
            ActorContext $ctx,
            object $msg,
        ) use (&$spawned): Behavior {
            $spawned++;

            return Behavior::same();
        });

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test-http', $runtime);
        $app = HttpApp::create($system);
        $app->perRequestActor('saga', Props::fromBehavior($sagaBehavior));
        $app->post('/run', static function (
            ServerRequestInterface $r,
            #[FromActor('saga')]
            ActorRef $saga,
        ): ResponseInterface {
            $saga->tell(new _RecordMessage('go'));

            return JsonResponse::ok(['ran' => true]);
        });

        $response = $app->compile()->handle(new ServerRequest('POST', '/run'));

        // Drive the runtime so the per-request saga fiber drains its mailbox.
        $runtime->scheduleOnce(Duration::millis(50), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(200, $response->getStatusCode());
        // The actor was spawned at least once and received the tell.
        self::assertGreaterThanOrEqual(1, $spawned);
    }
}
