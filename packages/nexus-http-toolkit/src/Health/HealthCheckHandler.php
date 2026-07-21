<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Detailed readiness handler — register against an INTERNAL or authenticated
 * route (e.g. a `readyz` endpoint reachable only from the cluster network or
 * behind auth). It exposes per-check states and details, which can reveal
 * internal topology and component information, so it must NOT be public. For
 * the public probe use {@see LivenessHandler}, which reports only the opaque
 * aggregate state.
 *
 *   $registry = (new HealthCheckRegistry())
 *       ->add(new DatabaseHealthCheck($pdo))
 *       ->add(new RedisHealthCheck($redis));
 *
 *   $app->get('/livez', new LivenessHandler($registry));                 // public
 *   $app->get('/readyz', new HealthCheckHandler($registry))             // internal
 *       ->middleware(AuthorizationMiddleware::class);
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
 *       "redis":    {"state": "down", "detail": {}}
 *     }
 *   }
 *
 * A check that THROWS is treated as down. By default the raw exception class
 * and message are REDACTED from the response (they can carry DSNs, hostnames,
 * or credentials); pass `includeErrorDetail: true` only for a trusted internal
 * readiness route to surface the exception class and message. The handler
 * itself never throws.
 */
final readonly class HealthCheckHandler
{
    public function __construct(private HealthCheckRegistry $registry, private bool $includeErrorDetail = false) {}

    public function __invoke(): ResponseInterface
    {
        $results = [];
        $anyDown = false;
        $anyDegraded = false;

        foreach ($this->registry->all() as $check) {
            try {
                $status = $check->check();
            } catch (Throwable $e) {
                $status = HealthStatus::down(
                    $this->includeErrorDetail
                        ? ['error' => $e::class, 'message' => $e->getMessage()]
                        : [],
                );
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
