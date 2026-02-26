<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Mailbox;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function ofCreatesWithEmptyMetadata(): void
    {
        $message = new stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');

        $envelope = Envelope::of($message, $sender, $target);

        self::assertSame($message, $envelope->message);
        self::assertTrue($envelope->sender->equals($sender));
        self::assertTrue($envelope->target->equals($target));
        self::assertNotSame('', $envelope->requestId);
        self::assertSame($envelope->requestId, $envelope->correlationId);
        self::assertSame($envelope->requestId, $envelope->causationId);
        self::assertSame([], $envelope->metadata);
    }

    #[Test]
    public function withMetadataReturnsNewInstanceWithUpdatedMetadata(): void
    {
        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/sender'),
            ActorPath::fromString('/target'),
        );

        $updated = $envelope->withMetadata(['trace-id' => 'abc-123']);

        self::assertNotSame($envelope, $updated);
        self::assertSame([], $envelope->metadata);
        self::assertSame(['trace-id' => 'abc-123'], $updated->metadata);
    }

    #[Test]
    public function withSenderReturnsNewInstanceWithUpdatedSender(): void
    {
        $originalSender = ActorPath::fromString('/original-sender');
        $newSender = ActorPath::fromString('/new-sender');

        $envelope = Envelope::of(
            new stdClass(),
            $originalSender,
            ActorPath::fromString('/target'),
        );

        $updated = $envelope->withSender($newSender);

        self::assertNotSame($envelope, $updated);
        self::assertTrue($envelope->sender->equals($originalSender));
        self::assertTrue($updated->sender->equals($newSender));
    }

    #[Test]
    public function immutabilityOriginalUnchangedAfterWither(): void
    {
        $message = new stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');

        $original = Envelope::of($message, $sender, $target);

        $original->withMetadata(['key' => 'value']);
        $original->withSender(ActorPath::fromString('/other'));

        self::assertSame($message, $original->message);
        self::assertTrue($original->sender->equals($sender));
        self::assertTrue($original->target->equals($target));
        self::assertSame([], $original->metadata);
    }

    #[Test]
    public function withContextIdsReturnsNewInstanceWithUpdatedIds(): void
    {
        $original = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/sender'),
            ActorPath::fromString('/target'),
        );

        $updated = $original
            ->withRequestId('request-2')
            ->withCorrelationId('correlation-2')
            ->withCausationId('causation-2');

        self::assertNotSame($original, $updated);
        self::assertSame('request-2', $updated->requestId);
        self::assertSame('correlation-2', $updated->correlationId);
        self::assertSame('causation-2', $updated->causationId);
    }

    #[Test]
    public function senderRefIsPreservedThroughWithMetadata(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::fromString('/target'))
            ->withSenderRef($ref);
        $updated = $envelope->withMetadata(['key' => 'value']);

        self::assertSame($ref, $updated->senderRef);
    }

    #[Test]
    public function senderRefIsPreservedThroughWithSender(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::fromString('/target'))
            ->withSenderRef($ref);
        $updated = $envelope->withSender(ActorPath::fromString('/new-sender'));

        self::assertSame($ref, $updated->senderRef);
    }

    #[Test]
    public function withSenderRefReturnsNewInstanceWithRef(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::fromString('/target'));

        self::assertNull($envelope->senderRef);

        $updated = $envelope->withSenderRef($ref);

        self::assertNotSame($envelope, $updated);
        self::assertSame($ref, $updated->senderRef);
    }

    #[Test]
    public function constructorAcceptsMetadata(): void
    {
        $message = new stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');

        $envelope = new Envelope(
            message: $message,
            sender: $sender,
            target: $target,
            requestId: 'request-1',
            correlationId: 'correlation-1',
            causationId: 'causation-1',
            metadata: ['request-id' => 'req-456'],
        );

        self::assertSame($message, $envelope->message);
        self::assertTrue($envelope->sender->equals($sender));
        self::assertTrue($envelope->target->equals($target));
        self::assertSame('request-1', $envelope->requestId);
        self::assertSame('correlation-1', $envelope->correlationId);
        self::assertSame('causation-1', $envelope->causationId);
        self::assertSame(['request-id' => 'req-456'], $envelope->metadata);
    }
}
