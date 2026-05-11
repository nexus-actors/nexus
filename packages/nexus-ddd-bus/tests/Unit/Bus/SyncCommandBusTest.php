<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Bus;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\BusNameNotRegisteredException;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(SyncCommandBus::class)]
final class SyncCommandBusTest extends TestCase
{
    #[Test]
    public function tryDispatchReturnsAcceptedOnSuccess(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer')],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryDispatch(new FakeCommand());

        self::assertTrue($result->isRight());
        self::assertInstanceOf(Accepted::class, $result->get());
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    #[Test]
    public function tryDispatchLiftsInfrastructureFailureToEitherLeft(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryDispatch(new FakeCommand());

        self::assertTrue($result->isLeft());
        self::assertSame($cause, $result->get());
    }

    #[Test]
    public function tryDispatchPropagatesBootInvariantsInsteadOfLifting(): void
    {
        $cause = BusNameNotRegisteredException::for('orders', []);
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->tryDispatch(new FakeCommand());
    }

    #[Test]
    public function tryDispatchLiftsRetryBudgetExhaustionToEitherLeft(): void
    {
        $cause = RetryBudgetExhaustedException::for(5, 250, new RuntimeException('inner'));
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryDispatch(new FakeCommand());

        self::assertTrue($result->isLeft());
        self::assertSame($cause, $result->get());
    }

    #[Test]
    public function tryDispatchLiftsValidationFailureToEitherLeft(): void
    {
        $cause = ValidationFailedException::with(new Violations([new Violation('code', 'invalid', 'field')]));
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $result = $bus->tryDispatch(new FakeCommand());

        self::assertTrue($result->isLeft());
        self::assertSame($cause, $result->get());
    }

    #[Test]
    public function dispatchCommandReturnsVoidOnSuccess(): void
    {
        $bus = $this->bus(new MiddlewarePipeline(
            [],
            static fn(Envelope $e): null => null,
        ));

        $bus->dispatchCommand(new FakeCommand());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function dispatchCommandRethrowsUnwrappedException(): void
    {
        $cause = new RuntimeException('boom');
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->dispatchCommand(new FakeCommand());
    }

    #[Test]
    public function dispatchCommandPropagatesBootInvariantsUnwrapped(): void
    {
        $cause = BusNameNotRegisteredException::for('orders', []);
        $bus = $this->bus(new MiddlewarePipeline(
            [new RecordingMiddleware('outer', throwOnEnter: $cause)],
            static fn(Envelope $e): null => null,
        ));

        $this->expectExceptionObject($cause);
        $bus->dispatchCommand(new FakeCommand());
    }

    #[Test]
    public function dispatchEnvelopedPassesEnvelopeThroughPipelineVerbatim(): void
    {
        $seenEnvelope = null;
        $core = static function (Envelope $env) use (&$seenEnvelope): null {
            $seenEnvelope = $env;

            return null;
        };
        $bus = $this->bus(new MiddlewarePipeline([new RecordingMiddleware('outer')], $core));

        $envelope = new Envelope(new FakeCommand(), MessageMetadata::root($this->fixedClock()));
        $bus->dispatchEnveloped($envelope);

        self::assertSame($envelope, $seenEnvelope);
        self::assertSame(['outer'], RecordingMiddleware::$log);
    }

    #[Test]
    public function tryDispatchBuildsRootMetadataFromInjectedClock(): void
    {
        $clockNow = new DateTimeImmutable('2026-05-10T12:34:56+00:00');
        $captured = null;

        $core = static function (Envelope $env) use (&$captured): null {
            $captured = $env;

            return null;
        };
        $bus = new SyncCommandBus(
            new BusRegistry(Profile::Sync, [], [], []),
            new HandlerAttributeIndex([]),
            new MiddlewarePipeline([], $core),
            Profile::Sync,
            $this->fixedClock($clockNow),
        );

        $bus->tryDispatch(new FakeCommand());

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame($clockNow, $captured->metadata->occurredAt);
        self::assertTrue($captured->metadata->isRoot());
    }

    protected function setUp(): void
    {
        RecordingMiddleware::resetLog();
    }

    private function bus(MiddlewarePipeline $pipeline): SyncCommandBus
    {
        return new SyncCommandBus(
            new BusRegistry(Profile::Sync, [], [], []),
            new HandlerAttributeIndex([]),
            $pipeline,
            Profile::Sync,
            $this->fixedClock(),
        );
    }

    private function fixedClock(?DateTimeImmutable $now = null): ClockInterface
    {
        $instant = $now ?? new DateTimeImmutable('2026-05-10T00:00:00+00:00');

        return new class ($instant) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}

final readonly class FakeCommand implements Command {}
