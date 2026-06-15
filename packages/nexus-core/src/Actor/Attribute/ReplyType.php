<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Attribute;

use Attribute;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Declares the expected reply type for a request-style message used with
 * `ActorRef::ask()`. The nexus-psalm AskReturnTypeProvider reads this
 * attribute at the call site and rewrites the inferred return from
 * `Future<R>` (where R is unconstrained) to `Future<DeclaredReply>` —
 * giving the caller full type information at compile time without any
 * runtime cost.
 *
 *   #[ReplyType(Order::class)]
 *   final readonly class GetOrder
 *   {
 *       public function __construct(public string $id) {}
 *   }
 *
 *   // At the call site:
 *   $order = $orders->ask(new GetOrder('42'), Duration::seconds(2))->await();
 *   //       ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
 *   // Psalm now infers: Order (not mixed)
 *
 * The attribute is metadata only — the runtime never inspects it.
 * The plugin's AskReturnTypeProvider does the work at static-analysis time.
 *
 * @param class-string $replyClass The class the actor will pass to
 *                                 `$ctx->reply(...)` when handling this
 *                                 message. Should usually be a readonly DTO.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ReplyType
{
    /** @param class-string $replyClass */
    public function __construct(public string $replyClass) {}
}
