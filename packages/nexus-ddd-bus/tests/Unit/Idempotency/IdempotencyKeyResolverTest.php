<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey as IdempotencyKeyAttribute;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(IdempotencyKeyResolver::class)]
final class IdempotencyKeyResolverTest extends TestCase
{
    #[Test]
    public function attributePathUsesNamedPropertyValue(): void
    {
        $resolver = new IdempotencyKeyResolver();
        $envelope = new Envelope(
            new IdempotencyKeyResolverAttributedCommand('abc-123'),
            $this->metadata(Headers::empty()),
        );

        $key = $resolver->resolve($envelope);

        self::assertSame('abc-123', $key->value);
    }

    #[Test]
    public function headerPathUsedWhenAttributeAbsent(): void
    {
        $resolver = new IdempotencyKeyResolver();
        $envelope = new Envelope(
            new stdClass(),
            $this->metadata(Headers::of([HeaderKeys::IDEMPOTENCY_KEY => 'header-val'])),
        );

        $key = $resolver->resolve($envelope);

        self::assertSame('header-val', $key->value);
    }

    #[Test]
    public function fallbackToMessageIdValueWhenNeitherAttributeNorHeader(): void
    {
        $resolver = new IdempotencyKeyResolver();
        $messageId = MessageId::generate();
        $envelope = new Envelope(
            new stdClass(),
            $this->metadata(Headers::empty(), $messageId),
        );

        $key = $resolver->resolve($envelope);

        self::assertSame($messageId->value(), $key->value);
    }

    private function metadata(Headers $headers, ?MessageId $id = null): MessageMetadata
    {
        return new MessageMetadata(
            id: $id ?? MessageId::generate(),
            occurredAt: new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC')),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
            headers: $headers,
        );
    }
}

#[IdempotencyKeyAttribute(field: 'clientRequestId')]
final readonly class IdempotencyKeyResolverAttributedCommand
{
    public function __construct(public string $clientRequestId) {}
}
