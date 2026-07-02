<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Override;
use Throwable;

use function fwrite;

/**
 * @psalm-api
 *
 * Drains Record messages from its mailbox and dispatches each to the
 * configured handlers. Per-handler exceptions are caught and written to
 * STDERR — the actor must never crash on a sink failure, because a crash
 * would lose any records sitting behind it in the mailbox.
 *
 * State is the immutable handler list. The list is supplied via the
 * Props factory at spawn time and never mutates.
 *
 * @implements StatefulActorHandler<object, list<Handler>>
 */
final class LogActor implements StatefulActorHandler
{
    /**
     * @param list<Handler> $handlers
     */
    public function __construct(private readonly array $handlers) {}

    #[Override]
    public function initialState(): mixed
    {
        return $this->handlers;
    }

    /**
     * @psalm-suppress BlockingCallInHandler — fwrite to STDERR is the
     *   intentional last-resort fallback when a Handler itself throws.
     *   The actor must not crash here; losing a log line is preferable
     *   to dropping every record sitting behind it in the mailbox.
     * @psalm-suppress MixedReturnTypeCoercion — BehaviorWithState::same()
     *   returns a parametrically-loose value that psalm cannot tighten to
     *   our specific <object, list<Handler>> binding.
     */
    #[Override]
    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        if (!$message instanceof Record) {
            return BehaviorWithState::same();
        }

        foreach ($state as $handler) {
            try {
                $handler->handle($message);
            } catch (Throwable $e) {
                fwrite(STDERR, "nexus-logger handler failed: {$e->getMessage()}\n");
            }
        }

        return BehaviorWithState::same();
    }
}
