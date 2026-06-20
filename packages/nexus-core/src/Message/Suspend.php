<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Message;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @internal Internal system message. Not for direct use.
 */
final readonly class Suspend implements SystemMessage {}
