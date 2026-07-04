<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http\ListInventoryHandler;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http\RestockHandler;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http\CancelOrderHandler;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http\GetOrderHandler;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http\ListOrdersHandler;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http\PlaceOrderHandler;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Ws\WsApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

use function json_encode;

/**
 * All routes in one place. Later milestones add /ws/* here.
 */
final class Routes
{
    public static function register(WsApplication $app, ReadinessProbe $probe): void
    {
        $app->get('/healthz', static fn(): ResponseInterface => self::json(200, ['status' => 'ok']));

        $app->get('/readyz', static function () use ($probe): ResponseInterface {
            $reason = $probe->check();

            return $reason === null
                ? self::json(200, ['status' => 'ready'])
                : self::json(503, ['reason' => $reason, 'status' => 'unready']);
        });

        $app->post('/api/orders', PlaceOrderHandler::class)
            ->middleware(AuthorizationMiddleware::class);
        $app->get('/api/orders', ListOrdersHandler::class)
            ->middleware(AuthorizationMiddleware::class);
        $app->get('/api/orders/{id}', GetOrderHandler::class)
            ->middleware(AuthorizationMiddleware::class);
        $app->delete('/api/orders/{id}', CancelOrderHandler::class)
            ->middleware(AuthorizationMiddleware::class);

        $app->post('/api/inventory/{sku}/restock', RestockHandler::class)
            ->middleware(AuthorizationMiddleware::class);
        $app->get('/api/inventory', ListInventoryHandler::class)
            ->middleware(AuthorizationMiddleware::class);
    }

    /**
     * @param array<string, string> $body
     */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Psr7Response(
            $status,
            ['content-type' => 'application/json'],
            (string) json_encode($body),
        );
    }
}
