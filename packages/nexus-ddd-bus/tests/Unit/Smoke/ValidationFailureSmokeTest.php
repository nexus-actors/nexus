<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingValidator;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncCommandBus::class)]
final class ValidationFailureSmokeTest extends TestCase
{
    #[Test]
    public function nonEmptyViolationsLiftToValidationFailedExceptionViaTryDispatch(): void
    {
        $violations = new Violations([new Violation('order.empty', 'orderId required', 'orderId')]);
        $harness = new PipelineHarness();
        $harness->validator = RecordingValidator::returning($violations);
        $handler = new ValidationFailureSmokeHandler();
        $harness->register(PlaceOrder::class, ValidationFailureSmokeHandler::class, $handler);
        $bus = $harness->build();

        $result = $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertTrue($result->isLeft());
        $error = $result->get();
        self::assertInstanceOf(ValidationFailedException::class, $error);
        self::assertSame($violations, $error->violations());
        self::assertSame([], $handler->received, 'handler must not run when validation fails');
    }
}

final class ValidationFailureSmokeHandler implements CommandHandler
{
    /** @var list<PlaceOrder> */
    public array $received = [];

    #[Validate]
    public function __invoke(PlaceOrder $command): void
    {
        $this->received[] = $command;
    }
}
