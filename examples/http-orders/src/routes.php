<?php

declare(strict_types=1);

namespace Examples\HttpOrders;

use Examples\HttpOrders\Domain\CreateOrder;
use Examples\HttpOrders\Domain\DeleteOrder;
use Examples\HttpOrders\Domain\GetOrder;
use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Http\Routing\Route;
use Psr\Http\Server\MiddlewareInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\concat;
use function Monadial\Nexus\Http\delete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\jsonBody;
use function Monadial\Nexus\Http\path;
use function Monadial\Nexus\Http\pathEnd;
use function Monadial\Nexus\Http\pathPrefix;
use function Monadial\Nexus\Http\post;
use function Monadial\Nexus\Http\useMiddlewares;

/**
 * Build the /orders route tree wrapped in the given middleware stack.
 *
 * @param list<MiddlewareInterface> $middlewares
 */
return static function (array $middlewares): Route {
    $orders = pathPrefix('orders', static fn(): Route => concat(
        get(static fn(): Route => path(
            IntNumber::class,
            static fn(int $id): Route => complete(
                static fn(RequestCtx $ctx): mixed => $ctx->ask('orders', new GetOrder($id)),
            ),
        )),
        post(static fn(): Route => pathEnd(
            static fn(): Route => jsonBody(
                CreateOrder::class,
                static fn(CreateOrder $cmd): Route => complete(
                    static fn(RequestCtx $ctx): mixed => $ctx->ask('orders', $cmd),
                    201,
                ),
            ),
        )),
        delete(static fn(): Route => path(
            IntNumber::class,
            static fn(int $id): Route => complete(
                static function (RequestCtx $ctx) use ($id): array {
                    $ref = $ctx->actorFor('orders');

                    if ($ref !== null) {
                        $ref->tell(new DeleteOrder($id));
                    }

                    return ['deleted' => $id];
                },
            ),
        )),
    ));

    /** @psalm-suppress ArgumentTypeCoercion illustrative example: $middlewares is typed via the surrounding closure docblock */
    return useMiddlewares($middlewares, static fn(): Route => $orders);
};
