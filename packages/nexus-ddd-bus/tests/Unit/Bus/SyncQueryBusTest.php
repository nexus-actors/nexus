<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Bus;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Bus\Bus\SyncQueryBus;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(SyncQueryBus::class)]
final class SyncQueryBusTest extends TestCase
{
    #[Test]
    public function tryAskReturnsRightWithPipelineResult(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer')],
            static fn(Envelope $e): string => 'the-answer',
        ));

        $result = $bus->tryAsk(new FakeQuery());

        self::assertTrue($result->isRight());
        self::assertSame('the-answer', $result->get());
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    #[Test]
    public function tryAskPropagatesBootInvariantsInsteadOfLifting(): void
    {
        $cause = BusNameNotRegisteredException::for('reporting', []);
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->tryAsk(new FakeQuery());
    }

    #[Test]
    public function tryAskLiftsInfrastructureFailureToEitherLeft(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryAsk(new FakeQuery());

        self::assertTrue($result->isLeft());
        self::assertSame($cause, $result->get());
    }

    #[Test]
    public function dispatchQueryReturnsResultOnSuccess(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [],
            static fn(Envelope $e): int => 42,
        ));

        self::assertSame(42, $bus->dispatchQuery(new FakeQuery()));
    }

    #[Test]
    public function dispatchQueryRethrowsUnwrappedException(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->dispatchQuery(new FakeQuery());
    }

    #[Test]
    public function dispatchEnvelopedReturnsPipelineResult(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer')],
            static fn(Envelope $e): string => 'enveloped-result',
        ));

        $envelope = new Envelope(new FakeQuery(), MessageMetadata::root($this->fixedClock()));

        self::assertSame('enveloped-result', $bus->dispatchEnveloped($envelope));
    }

    protected function setUp(): void
    {
        RecordingMiddleware::resetLog();
    }

    private function bus(MiddlewarePipeline $pipeline): SyncQueryBus
    {
        return new SyncQueryBus(
            new BusRegistry(Profile::Sync, [], [], []),
            new HandlerAttributeIndex([]),
            $pipeline,
            Profile::Sync,
            $this->fixedClock(),
        );
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-10T00:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}

/** @implements Query<string> */
final readonly class FakeQuery implements Query {}
