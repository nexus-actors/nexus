<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Exception;

use Monadial\Nexus\Persistence\PersistenceId;
use RuntimeException;

/** @psalm-api */
final class WriterConflictException extends RuntimeException
{
    public function __construct(
        public readonly PersistenceId $persistenceId,
        public readonly string $expectedWriter,
        public readonly string $actualWriter,
        public readonly int $sequenceNr,
    ) {
        parent::__construct(
            "Writer conflict for '{$persistenceId->toString()}' at sequence {$sequenceNr}: "
            . "expected writer '{$expectedWriter}', got '{$actualWriter}'",
        );
    }
}
