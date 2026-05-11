<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Middleware\ValidationMiddleware;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingValidator;
use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ValidationMiddleware::class)]
final class ValidationMiddlewareTest extends TestCase
{
    #[Test]
    public function passesThroughWhenIndexHasNoEntry(): void
    {
        $validator = RecordingValidator::returningEmpty();
        $index = new HandlerAttributeIndex([]);
        $envelope = $this->envelope();
        $nextCalled = false;

        $result = new ValidationMiddleware($validator, $index)->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                $nextCalled = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextCalled);
        self::assertSame([], $validator->calls);
    }

    #[Test]
    public function passesThroughWhenEntryHasNoValidateAttribute(): void
    {
        $validator = RecordingValidator::returningEmpty();
        $entry = $this->entry([]);
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);
        $envelope = $this->envelope();
        $nextCalled = false;

        $result = new ValidationMiddleware($validator, $index)->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                $nextCalled = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextCalled);
        self::assertSame([], $validator->calls);
    }

    #[Test]
    public function callsValidatorAndPassesThroughOnEmptyViolations(): void
    {
        $validator = RecordingValidator::returningEmpty();
        $entry = $this->entry([Validate::class => new Validate()]);
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);
        $envelope = $this->envelope();
        $nextCalled = false;

        $result = new ValidationMiddleware($validator, $index)->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                $nextCalled = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextCalled);
        self::assertCount(1, $validator->calls);
        self::assertSame($envelope->message, $validator->calls[0]['message']);
    }

    #[Test]
    public function liftsNonEmptyViolationsToValidationFailedException(): void
    {
        $violations = new Violations([new Violation('field.required', 'orderId is required', 'orderId')]);
        $validator = RecordingValidator::returning($violations);
        $entry = $this->entry([Validate::class => new Validate()]);
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);
        $envelope = $this->envelope();
        $nextCalled = false;
        $next = static function (Envelope $e) use (&$nextCalled): string {
            $nextCalled = true;

            return 'next';
        };

        try {
            new ValidationMiddleware($validator, $index)->process($envelope, Closure::fromCallable($next));
            self::fail('expected ValidationFailedException');
        } catch (ValidationFailedException $e) {
            self::assertSame($violations, $e->violations());
            self::assertFalse($nextCalled, '$next must not run after violations');
        }
    }

    #[Test]
    public function validationContextCarriesEnvelopeHeaders(): void
    {
        $validator = RecordingValidator::returningEmpty();
        $entry = $this->entry([Validate::class => new Validate()]);
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);
        $headers = Headers::of(['principal.id' => 'user-42']);
        $envelope = new Envelope(new stdClass(), $this->metadata($headers));

        new ValidationMiddleware($validator, $index)->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame($headers, $validator->calls[0]['context']->headers);
    }

    /**
     * @param array<class-string, object> $attributes
     */
    private function entry(array $attributes): ResolvedAttributesEntry
    {
        return new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\TestHandler',
            attributes: $attributes,
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );
    }

    /** @return Envelope<stdClass> */
    private function envelope(): Envelope
    {
        return new Envelope(new stdClass(), $this->metadata(Headers::empty()));
    }

    private function metadata(Headers $headers): MessageMetadata
    {
        return new MessageMetadata(
            id: MessageId::generate(),
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
