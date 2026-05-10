<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Exception;

use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;

use function sprintf;

/**
 * @psalm-api
 *
 * Thrown when a command chain exceeds the configured causation depth
 * limit. Terminal — runaway recursion will not resolve on retry.
 */
final class CausationDepthExceededException extends BusRuntimeException implements TerminalFailure
{
    public static function for(int $depth, int $limit): self
    {
        return new self(sprintf('Causation depth %d exceeds configured limit %d.', $depth, $limit));
    }
}
