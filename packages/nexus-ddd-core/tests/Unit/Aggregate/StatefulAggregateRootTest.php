<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(StatefulAggregateRoot::class)]
final class StatefulAggregateRootTest extends TestCase
{
    #[Test]
    public function recordThatStillAppliesAndEmits(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = StatefulSample::create($id);
        $a->setName('Ada');

        self::assertSame('Ada', $a->name);
        self::assertCount(1, $a->pullRecordedEvents());
    }
}

final class StatefulSample extends StatefulAggregateRoot
{
    public string $name = '';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->recordThat(new NameSet($name));
    }

    private function applyNameSet(NameSet $e): void
    {
        $this->name = $e->name;
    }
}

final readonly class NameSet
{
    public function __construct(public string $name) {}
}
