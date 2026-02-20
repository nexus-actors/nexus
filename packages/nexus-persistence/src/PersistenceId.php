<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence;

use InvalidArgumentException;
use Stringable;

readonly class PersistenceId implements Stringable
{
    private function __construct(
        public string $entityType,
        public string $entityId,
    ) {}

    public static function of(string $entityType, string $entityId): self
    {
        if ($entityType === '') {
            throw new InvalidArgumentException('Entity type must not be empty');
        }
        if ($entityId === '') {
            throw new InvalidArgumentException('Entity ID must not be empty');
        }
        if (str_contains($entityType, '|')) {
            throw new InvalidArgumentException('Entity type must not contain pipe character');
        }

        return new self($entityType, $entityId);
    }

    public static function fromString(string $value): self
    {
        $parts = explode('|', $value, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid persistence ID: {$value}");
        }

        return self::of($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return "{$this->entityType}|{$this->entityId}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->entityType === $other->entityType
            && $this->entityId === $other->entityId;
    }
}
