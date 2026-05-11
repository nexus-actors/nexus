<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\InProcess;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use Monadial\Nexus\Ddd\Bus\Routing\InProcessSameDbBootValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InProcessSameDbBootValidator::class)]
final class InProcessSameDbBootValidatorTest extends TestCase
{
    #[Test]
    public function emptyHandlerListIsNoOp(): void
    {
        new InProcessSameDbBootValidator([])->validate([]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function handlerWithoutInProcessMethodsIsNoOp(): void
    {
        new InProcessSameDbBootValidator([
            FixtureEvent::class => 'orders_write',
            HandlerWithoutInProcess::class => 'orders_write',
        ])->validate([HandlerWithoutInProcess::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function inProcessMethodWithoutFirstParameterIsNoOp(): void
    {
        new InProcessSameDbBootValidator([
            HandlerWithInProcessNoParams::class => 'orders_write',
        ])->validate([HandlerWithInProcessNoParams::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function inProcessMethodWithUntypedFirstParameterIsNoOp(): void
    {
        new InProcessSameDbBootValidator([
            FixtureEvent::class => 'shipments_write',
            HandlerWithInProcessUntyped::class => 'orders_write',
        ])->validate([HandlerWithInProcessUntyped::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function unboundAggregateOrHandlerIsNoOp(): void
    {
        new InProcessSameDbBootValidator([
            FixtureHandler::class => 'orders_write',
        ])->validate([FixtureHandler::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function bindingsThatAgreePassWithoutError(): void
    {
        new InProcessSameDbBootValidator([
            FixtureEvent::class => 'orders_write',
            FixtureHandler::class => 'orders_write',
        ])->validate([FixtureHandler::class]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function bindingsThatDifferThrowMismatch(): void
    {
        $this->expectException(InProcessConnectionMismatchException::class);

        new InProcessSameDbBootValidator([
            FixtureEvent::class => 'orders_write',
            FixtureHandler::class => 'shipments_write',
        ])->validate([FixtureHandler::class]);
    }
}

final readonly class FixtureEvent {}

final class FixtureHandler
{
    #[InProcess]
    public function on(FixtureEvent $event): void
    {
        // fixture: presence and signature drive validator reflection; body intentionally empty.
    }
}

final class HandlerWithoutInProcess
{
    public function on(FixtureEvent $event): void
    {
        // fixture: absence of #[InProcess] is the test subject; body intentionally empty.
    }
}

final class HandlerWithInProcessNoParams
{
    #[InProcess]
    public function on(): void
    {
        // fixture: zero-parameter form is the test subject; body intentionally empty.
    }
}

// phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint, SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
final class HandlerWithInProcessUntyped
{
    /** @psalm-suppress MissingParamType — fixture intentionally lacks the type to exercise the no-type branch. */
    #[InProcess]
    public function on($event): void
    {
        // fixture: untyped first parameter is the test subject; body intentionally empty.
    }
}
// phpcs:enable
