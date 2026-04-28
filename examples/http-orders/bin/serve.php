#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Examples\HttpOrders\Domain\OrderActor;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Middleware\LoggingMiddleware;
use Monadial\Nexus\Http\Middleware\RequestIdMiddleware;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Swoole\HttpServerBootstrap;
use Psr\Log\NullLogger;

$middlewares = [
    new RequestIdMiddleware(),
    new LoggingMiddleware(new NullLogger()),
];

/** @var callable(list<\Psr\Http\Server\MiddlewareInterface>): Route $routesFactory */
$routesFactory = require __DIR__ . '/../src/routes.php';
$routes        = $routesFactory($middlewares);

HttpServerBootstrap::dev($routes)
    ->host('0.0.0.0')
    ->port(8080)
    ->onSystemReady(static fn(ActorSystem $system) => $system->spawn(
        Props::fromBehavior(OrderActor::create()),
        'orders',
    ))
    ->run();
