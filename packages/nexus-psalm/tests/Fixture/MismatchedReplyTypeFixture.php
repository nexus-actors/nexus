<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;

final readonly class ReplyOrder
{
    public function __construct(public string $id) {}
}

final readonly class ReplyUser
{
    public function __construct(public int $id) {}
}

#[ReplyType(ReplyOrder::class)]
final readonly class FetchOrderMessage
{
    public function __construct(public string $id) {}
}

#[ReplyType(ReplyUser::class)]
final readonly class FetchUserMessage
{
    public function __construct(public int $id) {}
}

/**
 * Untagged message — no #[ReplyType]. Reply() calls with any value should
 * be allowed.
 */
final readonly class UntaggedFetch
{
    public function __construct(public string $key) {}
}

final class MismatchedReplyTypeFixture
{
    /**
     * Correct reply — no issue should fire.
     *
     * @param ActorContext<FetchOrderMessage> $ctx
     */
    public function correctReply(ActorContext $ctx, FetchOrderMessage $msg): void
    {
        $ctx->reply(new ReplyOrder($msg->id));
    }

    /**
     * Wrong reply — message expects ReplyOrder, we pass ReplyUser.
     * MismatchedReplyType MUST fire on the reply() call.
     *
     * @param ActorContext<FetchOrderMessage> $ctx
     */
    public function wrongReply(ActorContext $ctx, FetchOrderMessage $msg): void
    {
        $ctx->reply(new ReplyUser(42));
    }

    /**
     * Untagged message — no #[ReplyType] → no constraint → no issue.
     *
     * @param ActorContext<UntaggedFetch> $ctx
     */
    public function untaggedAllowsAnything(ActorContext $ctx, UntaggedFetch $msg): void
    {
        $ctx->reply(new ReplyOrder('anything'));
    }

    /**
     * match() pattern — narrowing branches each have ONE active message.
     * Both arms should be checked independently.
     *
     * @param ActorContext<object> $ctx
     */
    public function matchPattern(ActorContext $ctx, object $msg): void
    {
        if ($msg instanceof FetchOrderMessage) {
            $ctx->reply(new ReplyOrder($msg->id));   // OK
        }

        if ($msg instanceof FetchUserMessage) {
            $ctx->reply(new ReplyOrder('wrong'));    // MISMATCH: expects ReplyUser
        }
    }
}
