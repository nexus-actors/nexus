<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * The lifecycle phase at which a parameter is resolved. Tells resolvers which
 * data is available on the InvocationContext and gates attributes that only
 * make sense in some scopes.
 *
 *   HttpBoot     — HTTP handler constructor (runs once at boot; no request
 *                  available; services only).
 *   HttpRequest  — HTTP handler __invoke (per-request; full request +
 *                  PerRequestActorScope available).
 *   WsConnection — WebSocketHandler constructor (per-connection; full
 *                  request + WebSocketContext available).
 */
enum Scope
{
    case HttpBoot;
    case HttpRequest;
    case WsConnection;
}
