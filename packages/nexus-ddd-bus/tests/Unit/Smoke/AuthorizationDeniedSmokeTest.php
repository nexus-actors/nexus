<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingAuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncCommandBus::class)]
final class AuthorizationDeniedSmokeTest extends TestCase
{
    #[Test]
    public function deciderDenialLiftsToEitherLeftViaTryDispatch(): void
    {
        $denied = AccessDeniedException::for('order.place', 'order-1');
        $harness = new PipelineHarness();
        $harness->decider = RecordingAuthorizationDecider::throwingAccessDenied($denied);
        $handler = new AuthorizationDeniedSmokeHandler();
        $harness->register(PlaceOrder::class, AuthorizationDeniedSmokeHandler::class, $handler);
        $bus = $harness->build();

        $result = $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertTrue($result->isLeft());
        self::assertSame($denied, $result->get());
        self::assertSame([], $handler->received, 'handler must not run after authorization denial');
    }
}

final class AuthorizationDeniedSmokeHandler implements CommandHandler
{
    /** @var list<PlaceOrder> */
    public array $received = [];

    #[Authorize(policy: 'order.place', subject: 'orderId')]
    public function __invoke(PlaceOrder $command): void
    {
        $this->received[] = $command;
    }
}
