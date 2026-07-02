<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;

/**
 * Reply DTO that the actor will pass to $ctx->reply(...).
 */
final readonly class FixtureOrder
{
    public function __construct(public string $id, public float $total) {}
}

/**
 * Request message tagged with #[ReplyType] — the plugin's
 * AskReturnTypeProvider should rewrite the ask() return to
 * Future<FixtureOrder>.
 */
#[ReplyType(FixtureOrder::class)]
final readonly class GetOrderFixture
{
    public function __construct(public string $orderId) {}
}

/**
 * Request message WITHOUT #[ReplyType]. The plugin should leave the
 * return as the interface's unbound Future<R>.
 */
final readonly class UntaggedRequest
{
    public function __construct(public string $payload) {}
}

final class AskReplyTypeFixture
{
    /**
     * Tagged ask returning the narrowed Future. If the hook is correctly
     * connected, declaring the return as `Future<FixtureOrder>` matches —
     * if broken, Psalm reports MoreSpecificReturnType / InvalidReturnType.
     *
     * @param ActorRef<GetOrderFixture> $orders
     * @return Future<FixtureOrder>
     */
    public function taggedAskReturnsTypedFuture(ActorRef $orders): Future
    {
        return $orders->ask(new GetOrderFixture('42'), Duration::seconds(2));
    }

    /**
     * Tagged message → ->await() must return FixtureOrder so this assignment
     * type-checks WITHOUT a manual @var annotation.
     *
     * If the hook were broken, the return value here would be `object` (the
     * Future template upper bound) and Psalm would complain.
     *
     * @param ActorRef<GetOrderFixture> $orders
     */
    public function taggedAskAwaitReturnsTypedValue(ActorRef $orders): FixtureOrder
    {
        return $orders->ask(new GetOrderFixture('42'), Duration::seconds(2))->await();
    }

    /**
     * Mismatched declared return — Psalm SHOULD complain because the
     * hook tells it the future is Future<FixtureOrder>, not
     * Future<UntaggedRequest>. We assert the error appears in tests.
     *
     * @param ActorRef<GetOrderFixture> $orders
     * @return Future<UntaggedRequest>
     */
    public function taggedAskMismatchedReturnFails(ActorRef $orders): Future
    {
        return $orders->ask(new GetOrderFixture('42'), Duration::seconds(2));
    }
}
