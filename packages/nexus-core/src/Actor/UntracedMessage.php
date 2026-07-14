<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

/**
 * @psalm-api
 *
 * Marker for internal/infrastructure messages that should NOT get a per-message `process` span
 * or processing metrics. {@see ActorCell} skips instrumentation for messages implementing this.
 *
 * Use it on high-frequency control messages — timer ticks, coalesced liveness signals — whose
 * per-message telemetry adds no diagnostic value and, on a single-core reactor, perturbs
 * timing-sensitive loops (a failure detector measuring inter-arrival times is skewed if each of
 * its own tick messages mints and exports a span; the instrumentation disturbs what it observes).
 * Application messages should never implement this.
 */
interface UntracedMessage {}
