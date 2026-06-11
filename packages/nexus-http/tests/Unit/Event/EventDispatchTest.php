<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Event;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Http\Event\RouteMatched;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

final class _RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

#[CoversClass(RequestStarted::class)]
#[CoversClass(RequestCompleted::class)]
#[CoversClass(RouteMatched::class)]
final class EventDispatchTest extends TestCase
{
    #[Test]
    public function emits_three_events_in_order(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $dispatcher = new _RecordingDispatcher();
        $app = HttpApp::create($system, events: $dispatcher);
        $app->get('/x', static fn(): ResponseInterface => Response::ok());

        $app->compile()->handle(new ServerRequest('GET', '/x'));

        self::assertCount(3, $dispatcher->events);
        self::assertInstanceOf(RequestStarted::class, $dispatcher->events[0]);
        self::assertInstanceOf(RouteMatched::class, $dispatcher->events[1]);
        self::assertInstanceOf(RequestCompleted::class, $dispatcher->events[2]);
    }
}
