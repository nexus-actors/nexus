<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Health;

use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Opaque liveness endpoint safe to expose publicly (load balancers,
 * Kubernetes livenessProbe). It reports ONLY the aggregate up/down state —
 * never check names, per-check details, or exception messages — so it cannot
 * leak internal topology or component/error information.
 *
 *   $app->get('/livez', new LivenessHandler($registry));
 *
 * Response:
 *   200  {"status":"up"}     — no check is down
 *   503  {"status":"down"}   — at least one check is down
 *
 * For the detailed component view (check states, controlled error detail),
 * use {@see HealthCheckHandler} on an INTERNAL or authenticated route.
 * A check that throws is treated as down; this handler never throws.
 */
final readonly class LivenessHandler
{
    public function __construct(private HealthCheckRegistry $registry) {}

    public function __invoke(): ResponseInterface
    {
        $anyDown = false;

        foreach ($this->registry->all() as $check) {
            try {
                $status = $check->check();
            } catch (Throwable) {
                $anyDown = true;

                continue;
            }

            if ($status->state === State::Down) {
                $anyDown = true;
            }
        }

        return JsonResponse::ok(['status' => $anyDown ? 'down' : 'up'])
            ->withStatus($anyDown ? 503 : 200);
    }
}
