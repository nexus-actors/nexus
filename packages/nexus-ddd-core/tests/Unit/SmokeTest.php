<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit;

use Fp\Functional\Either\Either;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Backoff\ExponentialBackoff;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicyBuilder;
use Monadial\Nexus\Ddd\Core\Identity\IdGenerator;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Identity\UlidGenerator;
use Monadial\Nexus\Ddd\Core\Policy\AbstractPolicy;
use Monadial\Nexus\Ddd\Core\Specification\AbstractRichSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Failure;
use Monadial\Nexus\Ddd\Core\Value\StringValue;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmokeTest extends TestCase
{
    #[Test]
    public function fullPackageIntegrationSmoke(): void
    {
        // Identity
        $gen = new UlidGenerator();
        self::assertInstanceOf(IdGenerator::class, $gen);
        $id = $gen->next();
        self::assertInstanceOf(UlidValue::class, $id);

        // Value object — wrapped, mappable
        $email = new SmokeEmail('alice@example.com');
        $upper = $email->map(strtoupper(...));
        self::assertSame('ALICE@EXAMPLE.COM', $upper->asString());

        // Aggregate — record-and-apply
        $order = SmokeOrder::create($id);
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

        // Backoff — RetryPolicy
        $retry = RetryPolicyBuilder::create()
            ->onException(RuntimeException::class, ExponentialBackoff::of(
                FiniteDuration::fromTimeUnit(10, TimeUnit::Milliseconds()),
                FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
                3,
            ))
            ->build();
        $delay = $retry->delayFor(1, new RuntimeException('boom'));
        self::assertFalse($delay->isNone());
    }
}

final readonly class SmokeEmail extends StringValue
{
    public function asString(): string
    {
        /** @var string $v */
        $v = $this->getValue();

        return $v;
    }
}

final class SmokeOrder extends EventSourcedAggregateRoot
{
    public string $status = 'new';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    public function place(): void
    {
        $this->recordThat(new SmokePlaced());
    }

    private function applySmokePlaced(SmokePlaced $e): void
    {
        $this->status = 'placed';
    }
}

final readonly class SmokePlaced {}

/** @extends AbstractRichSpecification<string> */
final class SmokeNonEmpty extends AbstractRichSpecification
{
    #[\Override]
    public function evaluate(mixed $candidate): Either
    {
        if (is_string($candidate) && $candidate !== '') {
            return Either::right($candidate);
        }

        return Either::left([new Failure('value', 'empty', 'must be non-empty string')]);
    }
}

/** @extends AbstractPolicy<int, int> */
final class SmokeDoublePolicy extends AbstractPolicy
{
    #[\Override]
    public function apply(mixed $input): mixed
    {
        return (int) $input * 2;
    }
}
