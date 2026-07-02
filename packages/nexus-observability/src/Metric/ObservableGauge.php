<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Metric;

/**
 * @psalm-api
 *
 * Marker for an asynchronous gauge. The current value is produced by the
 * callback registered via {@see Meter::observableGauge()}; there is no
 * imperative record call.
 */
interface ObservableGauge {}
