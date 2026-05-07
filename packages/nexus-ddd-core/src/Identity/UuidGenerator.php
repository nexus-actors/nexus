<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use Symfony\Component\Uid\Uuid;

/**
 * @psalm-api
 *
 * Generates fresh UUID v7 identifiers for the configured domain class.
 *
 * @template T of UuidValue
 * @implements IdGenerator<T>
 */
final class UuidGenerator implements IdGenerator
{
    /**
     * @param class-string<T> $idClass
     */
    public function __construct(private string $idClass) {}

    /** @return T */
    #[\Override]
    public function next(): Identifier
    {
        return $this->idClass::fromString((string) Uuid::v7());
    }
}
