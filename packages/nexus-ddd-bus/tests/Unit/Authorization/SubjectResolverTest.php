<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Authorization;

use DateTimeImmutable;
use LogicException;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

use function assert;

#[CoversClass(SubjectResolver::class)]
final class SubjectResolverTest extends TestCase
{
    #[Test]
    public function stringFormReadsNamedPropertyFromMessage(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-7');
        $ctx = $this->messageContext();

        $subject = $resolver->resolve($message, 'userId', $ctx);

        self::assertSame('user-7', $subject);
    }

    #[Test]
    public function callableFormInvokesStaticMethodWithMessageAndContext(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-9');
        $ctx = $this->messageContext();

        $subject = $resolver->resolve($message, SubjectResolverFixtureMessage::class . '::pickUserId', $ctx);

        self::assertSame('user-9', $subject);
    }

    #[Test]
    public function stringFormThrowsLogicExceptionWhenPropertyMissing(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-1');
        $ctx = $this->messageContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Property `missing` does not exist on');

        $resolver->resolve($message, 'missing', $ctx);
    }

    #[Test]
    public function callableFormThrowsLogicExceptionWhenClassMissing(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-1');
        $ctx = $this->messageContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('class or method does not exist');

        $resolver->resolve($message, 'NoSuchClass::nope', $ctx);
    }

    #[Test]
    public function callableFormThrowsLogicExceptionWhenMethodIsNotStatic(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-1');
        $ctx = $this->messageContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must reference a public static method');

        $resolver->resolve(
            $message,
            SubjectResolverFixtureMessage::class . '::instanceMethod',
            $ctx,
        );
    }

    #[Test]
    public function callableFormThrowsLogicExceptionWhenMethodIsPrivate(): void
    {
        $resolver = new SubjectResolver();
        $message = new SubjectResolverFixtureMessage('user-1');
        $ctx = $this->messageContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must reference a public static method');

        $resolver->resolve(
            $message,
            SubjectResolverFixtureMessage::class . '::privateStatic',
            $ctx,
        );
    }

    private function messageContext(): MessageContext
    {
        return new MessageContext(MessageMetadata::root($this->fixedClock()));
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            #[Override]
            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}

final readonly class SubjectResolverFixtureMessage
{
    public function __construct(public string $userId) {}

    public static function pickUserId(object $message, MessageContext $ctx): string
    {
        assert($message instanceof self);

        return $message->userId;
    }

    /** @psalm-suppress PossiblyUnusedMethod — fixture surfaces a non-static method for SubjectResolver tightening test. */
    public function instanceMethod(object $message, MessageContext $ctx): string
    {
        return $this->userId;
    }

    /** @psalm-suppress UnusedMethod — fixture surfaces a private static method for SubjectResolver tightening test. */
    private static function privateStatic(object $message, MessageContext $ctx): string
    {
        return 'never';
    }
}
