<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Throwable;

/**
 * @psalm-api
 *
 * Thrown when a stream buffer violates the cluster TCP framing protocol —
 * e.g. an unknown frame type, a declared frame length that exceeds the
 * configured maximum, or a structurally invalid frame header.
 */
final class ProtocolException extends NexusException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
