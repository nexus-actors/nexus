<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

/**
 * @psalm-api
 *
 * Marker for boot-invariant exceptions. `tryDispatch()` PROPAGATES these
 * (does NOT lift to `Either::left`) — boot-time configuration errors are
 * not domain failures. Adopters catch these in the composition root, not
 * in handlers.
 */
interface BusInvariantException {}
