<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Bus;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Bus\Bus\SyncEventBus;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(SyncEventBus::class)]
final class SyncEventBusTest extends TestCase
{
    #[Test]
    public function tryPublishReturnsAcceptedOnSuccess(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer')],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryPublish(new FakeEvent());

        self::assertTrue($result->isRight());
        self::assertInstanceOf(Accepted::class, $result->get());
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    #[Test]
    public function tryPublishPropagatesBootInvariantsInsteadOfLifting(): void
    {
        $cause = BusNameNotRegisteredException::for('outbox', []);
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->tryPublish(new FakeEvent());
    }

    #[Test]
    public function tryPublishLiftsInfrastructureFailureToEitherLeft(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryPublish(new FakeEvent());

        self::assertTrue($result->isLeft());
        self::assertSame($cause, $result->get());
    }

    #[Test]
    public function publishEventReturnsVoidOnSuccess(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [],
            static fn(Envelope $e): null => null,
        ));

        $bus->publishEvent(new FakeEvent());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function publishEventRethrowsUnwrappedException(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->publishEvent(new FakeEvent());
    }

    #[Test]
    public function publishEnvelopedPassesEnvelopeThroughPipelineVerbatim(): void
    {
        $seenEnvelope = null;
        $core = static function (Envelope $env) use (&$seenEnvelope): null {
            $seenEnvelope = $env;

            return null;
        };
        $bus = $this->bus(new MiddlewarePipeline([new RecordingMiddleware('outer')], $core));

        $envelope = new Envelope(new FakeEvent(), MessageMetadata::root($this->fixedClock()));
        $bus->publishEnveloped($envelope);

        self::assertSame($envelope, $seenEnvelope);
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    protected function setUp(): void
    {
        RecordingMiddleware::resetLog();
    }

    private function bus(MiddlewarePipeline $pipeline): SyncEventBus
    {
        return new SyncEventBus(
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

final readonly class FakeEvent implements DomainEvent {}
