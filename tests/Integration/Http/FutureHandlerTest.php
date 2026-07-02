<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureResult;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

#[CoversNothing]
final class FutureHandlerTest extends TestCase
{
    #[Test]
    public function future_returning_handler_is_awaited(): void
    {
        $a = new stdClass();
        $a->name = 'a';
        $b = new stdClass();
        $b->name = 'b';

        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get('/fan-out', static fn(ServerRequestInterface $r): Future => Future::all([
            'a' => Future::resolved($a),
            'b' => Future::resolved($b),
        ])->map(static fn(FutureResult $r): ResponseInterface => JsonResponse::ok([
            'a' => $r->values['a']->name,
            'b' => $r->values['b']->name,
        ])));

        $response = $app->compile()->handle(new ServerRequest('GET', '/fan-out'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"a":"a","b":"b"}', (string) $response->getBody());
    }
}
