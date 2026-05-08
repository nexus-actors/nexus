<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\IdGenerator;
use Monadial\Nexus\Ddd\Core\Identity\UlidGenerator;
use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use Monadial\Nexus\Ddd\Core\Specification\AbstractRichSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Failure;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Monadial\Nexus\Ddd\Core\Value\Extractor\StringExtractor;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function fullPackageIntegrationSmoke(): void
    {
        // Identity — generator parameterized by concrete domain Id class
        $gen = new UlidGenerator(TestUlidId::class);
        self::assertInstanceOf(IdGenerator::class, $gen);
        $id = $gen->next();
        self::assertInstanceOf(UlidValue::class, $id);
        self::assertInstanceOf(TestUlidId::class, $id);

        // Value object — wrapped, mappable; raw value read via typed extractor
        $email = new SmokeEmail('alice@example.com');
        $upper = $email->map(strtoupper(...));
        self::assertSame('ALICE@EXAMPLE.COM', StringExtractor::extract($upper));

        // Aggregate — record-and-apply
        $order = SmokeOrder::create($id, new ApplyDispatcher());
        $order->place();
        self::assertSame('placed', $order->status);
        self::assertCount(1, $order->pullRecordedEvents());

        // Specification — bool + rich
        $rich = new SmokeNonEmpty();
        $result = $rich->evaluate('hello');
        self::assertTrue($result->isRight());

        // Policy — compute
        $policy = new SmokeDoublePolicy();
        self::assertSame(8, $policy->apply(4));
    }
}

/** @psalm-immutable */
final readonly class SmokeEmail extends StringValue {}

/** @extends EventSourcedAggregateRoot<TestUlidId, SmokePlaced> */
final class SmokeOrder extends EventSourcedAggregateRoot
{
    public string $status = 'new';

    public static function create(TestUlidId $id, ApplyDispatcher $dispatcher,): self
    {
        return new self($id, $dispatcher);
    }

    #[Override]
    public function id(): TestUlidId
    {
        /** @var TestUlidId */
        return $this->id;
    }

    public function place(): void
    {
        $this->recordThat(new SmokePlaced());
    }

    private function applySmokePlaced(SmokePlaced $_e): void
    {
        $this->status = 'placed';
    }
}

final readonly class SmokePlaced implements DomainEvent {}

/** @extends AbstractRichSpecification<string> */
final class SmokeNonEmpty extends AbstractRichSpecification
{
    #[Override]
    public function evaluate(mixed $candidate): Either
    {
        if (is_string($candidate) && $candidate !== '') {
            return Either::right($candidate);
        }

        return Either::left([new Failure('value', 'empty', 'must be non-empty string')]);
    }
}

/** @psalm-suppress MissingImmutableAnnotation */

/** @extends AbstractPolicy<int, int> */
final class SmokeDoublePolicy extends AbstractPolicy
{
    #[Override]
    public function apply(mixed $input): mixed
    {
        /** @var int $input */
        return $input * 2;
    }
}
