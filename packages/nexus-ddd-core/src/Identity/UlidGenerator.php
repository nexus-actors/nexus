<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Identity;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Override;
use Symfony\Component\Uid\Ulid;

/**
 * @psalm-api
 *
 * Generates fresh ULID-backed identifiers for the configured domain class.
 *
 * Usage:
 *
 *   final readonly class OrderId extends UlidValue {}
 *   $generator = new UlidGenerator(OrderId::class);
 *   $id = $generator->next();  // type-inferred as OrderId
 *
 * @template T of UlidValue
 * @implements IdGenerator<T>
 */
final class UlidGenerator implements IdGenerator
{
    /**
     * @param class-string<T> $idClass
     */
    public function __construct(private string $idClass) {}

    /** @return T */
    #[Override]
    public function next(): Identifier
    {
        return $this->idClass::fromString((new Ulid())->toBase32());
    }
}
