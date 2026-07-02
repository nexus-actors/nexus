<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Invokable handler — register against a route to serve the aggregate
 * health endpoint:
 *
 *   $registry = (new HealthCheckRegistry())
 *       ->add(new DatabaseHealthCheck($pdo))
 *       ->add(new RedisHealthCheck($redis));
 *
 *   $app->get('/health', new HealthCheckHandler($registry));
 *
 * Response shape (RFC-health-json-inspired):
 *
 *   200 OK  (everything up or degraded)
 *   503     (any check is down)
 *
 *   {
 *     "status": "up" | "degraded" | "down",
 *     "checks": {
 *       "database": {"state": "up",   "detail": {"latencyMs": 1.2}},
 *       "redis":    {"state": "down", "detail": {"error": "connection refused"}}
 *     }
 *   }
 *
 * A check that THROWS is treated as down; the exception class becomes
 * the `error` detail. The handler itself never throws.
 */
final readonly class HealthCheckHandler
{
    public function __construct(private HealthCheckRegistry $registry) {}

    public function __invoke(): ResponseInterface
    {
        $results = [];
        $anyDown = false;
        $anyDegraded = false;

        foreach ($this->registry->all() as $check) {
            try {
                $status = $check->check();
            } catch (Throwable $e) {
                $status = HealthStatus::down(['error' => $e::class, 'message' => $e->getMessage()]);
            }

            $results[$check->name()] = [
                'detail' => $status->detail,
                'state'  => $status->state->value,
            ];

            if ($status->state === State::Down) {
                $anyDown = true;
            } elseif ($status->state === State::Degraded) {
                $anyDegraded = true;
            }
        }

        $overall = match (true) {
            $anyDown     => State::Down,
            $anyDegraded => State::Degraded,
            default      => State::Up,
        };

        $statusCode = $anyDown
            ? 503
            : 200;

        return JsonResponse::ok([
            'checks' => $results,
            'status' => $overall->value,
        ])->withStatus($statusCode);
    }
}
