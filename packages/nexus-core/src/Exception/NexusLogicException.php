<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use LogicException;

/**
 * @psalm-api
 *
 * Base for unchecked Nexus exceptions.
 * Programmer errors — bugs, invariant violations.
 * NOT tracked by checked exception analysis.
 */
abstract class NexusLogicException extends LogicException {}
