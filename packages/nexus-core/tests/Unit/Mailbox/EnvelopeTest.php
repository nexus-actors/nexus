<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Mailbox;

use Fp\Collections\HashMap;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function ofCreatesWithEmptyMetadata(): void
    {
        $message = new \stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');

        $envelope = Envelope::of($message, $sender, $target);

        self::assertSame($message, $envelope->message);
        self::assertTrue($envelope->sender->equals($sender));
        self::assertTrue($envelope->target->equals($target));
        self::assertSame([], $envelope->metadata->toArray());
    }

    #[Test]
    public function withMetadataReturnsNewInstanceWithUpdatedMetadata(): void
    {
        $envelope = Envelope::of(
            new \stdClass(),
            ActorPath::fromString('/sender'),
            ActorPath::fromString('/target'),
        );

        /** @var HashMap<string, string> $metadata */
        $metadata = HashMap::collectPairs([['trace-id', 'abc-123']]);
        $updated = $envelope->withMetadata($metadata);

        self::assertNotSame($envelope, $updated);
        self::assertSame([], $envelope->metadata->toArray());
        self::assertSame(['trace-id' => 'abc-123'], $updated->metadata->toArray());
    }

    #[Test]
    public function withSenderReturnsNewInstanceWithUpdatedSender(): void
    {
        $originalSender = ActorPath::fromString('/original-sender');
        $newSender = ActorPath::fromString('/new-sender');

        $envelope = Envelope::of(
            new \stdClass(),
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
        $message = new \stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');

        $original = Envelope::of($message, $sender, $target);

        /** @var HashMap<string, string> $metadata */
        $metadata = HashMap::collectPairs([['key', 'value']]);
        $original->withMetadata($metadata);
        $original->withSender(ActorPath::fromString('/other'));

        self::assertSame($message, $original->message);
        self::assertTrue($original->sender->equals($sender));
        self::assertTrue($original->target->equals($target));
        self::assertSame([], $original->metadata->toArray());
    }

    #[Test]
    public function constructorAcceptsMetadata(): void
    {
        $message = new \stdClass();
        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');
        /** @var HashMap<string, string> $metadata */
        $metadata = HashMap::collectPairs([['request-id', 'req-456']]);

        $envelope = new Envelope($message, $sender, $target, $metadata);

        self::assertSame($message, $envelope->message);
        self::assertTrue($envelope->sender->equals($sender));
        self::assertTrue($envelope->target->equals($target));
        self::assertSame(['request-id' => 'req-456'], $envelope->metadata->toArray());
    }
}
