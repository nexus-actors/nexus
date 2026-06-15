<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

/**
 * @psalm-api
 *
 * Raised when a handler calls `$ctx->reply($x)` with a value whose type
 * doesn't match the `#[ReplyType]` declared on the message being handled.
 *
 * Example:
 *
 *     #[ReplyType(Order::class)]
 *     readonly class GetOrder { public function __construct(public string $id) {} }
 *
 *     // In a handler:
 *     if ($msg instanceof GetOrder) {
 *         $ctx->reply(new User(...));  // ← MismatchedReplyType: expected Order, got User
 *     }
 */
final class MismatchedReplyType extends PluginIssue
{
    public function __construct(
        string $messageClass,
        string $expectedReply,
        string $actualReply,
        CodeLocation $codeLocation,
    ) {
        parent::__construct(
            'Handler reply for message "' . $messageClass . '" should return "' . $expectedReply . '" '
            . '(declared via #[ReplyType]) but got "' . $actualReply . '". '
            . 'Either update the reply value, change the #[ReplyType] declaration, or '
            . 'remove the #[ReplyType] attribute if this message has no canonical reply.',
            $codeLocation,
        );
    }
}
