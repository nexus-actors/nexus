<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

/**
 * @psalm-immutable
 */
final readonly class CloneWithFixture
{
    public function __construct(
        public string $name,
        public int $value,
    ) {
    }

    public function withName(string $name): self
    {
        return clone($this, ['name' => $name]);
    }

    public function withValue(int $value): self
    {
        return clone($this, ['value' => $value]);
    }
}

function testCloneWithReturnType(): void
{
    $a = new CloneWithFixture('hello', 42);
    $b = $a->withName('world');

    // Psalm should know $b is CloneWithFixture, not object
    /** @psalm-trace $b */
    $_ = $b->name;
}
