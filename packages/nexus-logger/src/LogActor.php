<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Drains Record messages from its mailbox and dispatches each to the
 * configured handlers. Per-handler exceptions are caught and reported to
 * STDERR — the actor must never crash on a sink failure, because a crash
 * would lose any records sitting behind it in the mailbox.
 *
 * State is the immutable handler list. The list is supplied via the
 * Props factory at spawn time and never mutates.
 *
 * @implements StatefulActorHandler<Record, list<Handler>>
 */
final readonly class LogActor implements StatefulActorHandler
{
    /**
     * @param list<Handler> $handlers
     */
    public function __construct(private array $handlers) {}

    /**
     * @return list<Handler>
     */
    #[Override]
    public function initialState(): mixed
    {
        return $this->handlers;
    }

    /**
     * @param ActorContext<Record> $ctx
     * @param object $message Wire type; anything other than a Record is ignored defensively.
     * @param list<Handler> $state
     * @return BehaviorWithState<Record, list<Handler>>
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
                HandlerFailureReporter::report($e);
            }
        }

        return BehaviorWithState::same();
    }
}
