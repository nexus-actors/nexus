<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Error\ErrorMapper;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class RouteTestKit
{
    private ServerRequestInterface $request;
    private MarshallerRegistry $registry;
    private LoggerInterface $logger;
    private ErrorMapper $errorMapper;
    private ?ActorSystem $system = null;

    public function __construct(public readonly Route $route)
    {
        $factory = new Psr17Factory();
        $this->request = $factory->createServerRequest('GET', '/');
        $this->registry = MarshallerRegistry::withDefaults();
        $this->logger = new NullLogger();
        $this->errorMapper = new DefaultErrorMapper();
    }

    public static function route(Route $route): self
    {
        return new self($route);
    }

    public function withSystem(ActorSystem $system): self
    {
        $this->system = $system;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->request = $this->request->withHeader($name, $value);

        return $this;
    }

    public function get(string $uri): self
    {
        $factory = new Psr17Factory();
        $this->request = $this->request->withMethod('GET')->withUri($factory->createUri($uri));

        return $this;
    }

    public function post(string $uri, string $body = ''): self
    {
        $factory = new Psr17Factory();
        $this->request = $this->request->withMethod('POST')
            ->withUri($factory->createUri($uri))
            ->withBody($factory->createStream($body));

        return $this;
    }

    public function run(): RouteResult
    {
        $system = $this->system ?? ActorSystem::create('testkit', new StepRuntime());

        $ctx = new DefaultRequestCtx(
            request: $this->request,
            params: [],
            system: $system,
            registry: $this->registry,
            logger: $this->logger,
        );

        try {
            $response = ($this->route->run)($ctx) ?? new Response(404);
        } catch (Throwable $e) {
            $response = $this->errorMapper->map($e, $ctx);
        }

        return new RouteResult($response);
    }
}
