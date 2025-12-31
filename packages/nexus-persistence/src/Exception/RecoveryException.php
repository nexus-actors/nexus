<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Exception;

use Monadial\Nexus\Persistence\PersistenceId;

class RecoveryException extends \RuntimeException
{
    public function __construct(
        public readonly PersistenceId $persistenceId,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
