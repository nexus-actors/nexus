<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Http\Ws\WsApplication;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

use function json_encode;

/**
 * All routes in one place. Later milestones add /api/* and /ws/* here.
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
