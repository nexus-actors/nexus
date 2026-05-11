<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Authorization\NoPrincipalProvider;
use Monadial\Nexus\Ddd\Bus\Authorization\Principal;
use Monadial\Nexus\Ddd\Bus\Authorization\PrincipalProvider;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Bus\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingAuthorizationDecider;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    #[Test]
    public function passesThroughWhenIndexHasNoEntry(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );
        $envelope = $this->envelope(new stdClass());
        $nextCalled = false;

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                $nextCalled = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextCalled);
        self::assertSame([], $decider->calls);
    }

    #[Test]
    public function passesThroughWhenEntryHasNoAuthorizeAttribute(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $entry = $this->entry([]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );
        $envelope = $this->envelope(new stdClass());

        $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame([], $decider->calls);
    }

    #[Test]
    public function resolvesSubjectFromPropertyAndCallsDecider(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $message = new AuthorizationMiddlewareTestCommand('order-123');
        $entry = $this->entry([Authorize::class => new Authorize(policy: 'order.cancel', subject: 'orderId')]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([AuthorizationMiddlewareTestCommand::class => $entry]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );
        $envelope = $this->envelope($message);

        $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertCount(1, $decider->calls);
        self::assertSame('order.cancel', $decider->calls[0]['policy']);
        self::assertSame('order-123', $decider->calls[0]['subject']);
        self::assertSame($envelope, $decider->calls[0]['context']->envelope);
    }

    #[Test]
    public function nullSubjectStillInvokesDeciderWithNull(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $entry = $this->entry([Authorize::class => new Authorize(policy: 'admin.access')]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );

        $middleware->process(
            $this->envelope(new stdClass()),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertCount(1, $decider->calls);
        self::assertSame('admin.access', $decider->calls[0]['policy']);
        self::assertNull($decider->calls[0]['subject']);
    }

    #[Test]
    public function deciderDenialPropagatesAndShortCircuitsNext(): void
    {
        $denied = AccessDeniedException::for('order.cancel', 'order-123');
        $decider = RecordingAuthorizationDecider::throwingAccessDenied($denied);
        $message = new AuthorizationMiddlewareTestCommand('order-123');
        $entry = $this->entry([Authorize::class => new Authorize(policy: 'order.cancel', subject: 'orderId')]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([AuthorizationMiddlewareTestCommand::class => $entry]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );
        $nextCalled = false;

        try {
            $middleware->process(
                $this->envelope($message),
                Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                    $nextCalled = true;

                    return 'next';
                }),
            );
            self::fail('expected AccessDeniedException');
        } catch (AccessDeniedException $e) {
            self::assertSame($denied, $e);
            self::assertFalse($nextCalled);
        }
    }

    #[Test]
    public function authorizationContextCarriesPrincipalFromProvider(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $principal = new AuthorizationMiddlewareTestPrincipal('alice');
        $entry = $this->entry([Authorize::class => new Authorize(policy: 'admin.access')]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            MessageContextStack::default(),
            new FixedPrincipalProvider($principal),
        );

        $middleware->process(
            $this->envelope(new stdClass()),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertCount(1, $decider->calls);
        self::assertTrue($decider->calls[0]['context']->principal->isSome());
        self::assertSame($principal, $decider->calls[0]['context']->principal->getUnsafe());
    }

    #[Test]
    public function authorizationContextCarriesNoneWhenProviderReturnsNone(): void
    {
        $decider = RecordingAuthorizationDecider::allowing();
        $entry = $this->entry([Authorize::class => new Authorize(policy: 'admin.access')]);
        $middleware = new AuthorizationMiddleware(
            $decider,
            new SubjectResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            MessageContextStack::default(),
            new NoPrincipalProvider(),
        );

        $middleware->process(
            $this->envelope(new stdClass()),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertCount(1, $decider->calls);
        self::assertTrue($decider->calls[0]['context']->principal->isNone());
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

    /**
     * @template T of object
     * @param T $message
     * @return Envelope<T>
     */
    private function envelope(object $message): Envelope
    {
        return new Envelope(
            $message,
            new MessageMetadata(
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
            ),
        );
    }
}

/**
 * Test-local message with a public property `orderId` so
 * `SubjectResolver` can read the subject from the string form.
 */
final readonly class AuthorizationMiddlewareTestCommand
{
    public function __construct(public string $orderId) {}
}

final readonly class AuthorizationMiddlewareTestPrincipal implements Principal
{
    public function __construct(private string $id) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }
}

final readonly class FixedPrincipalProvider implements PrincipalProvider
{
    public function __construct(private Principal $principal) {}

    /** @return Option<Principal> */
    #[Override]
    public function current(): Option
    {
        return Option::some($this->principal);
    }
}
