<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Base for runtime contract violations detected during dispatch — OCC
 * retry budget exhaustion, causation depth overruns, in-process
 * connection mismatches, actor-writer invariant breaches.
 */
abstract class BusRuntimeException extends BusException {}
