<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;

/**
 * @psalm-api
 *
 * Smoke fixture: handler that publishes a UserRegistered event derived from
 * the current command context. Captures the command's MessageId via the
 * supplied Closure so the smoke test can assert event.causationId equals
 * the command id.
 */
final readonly class RegisterUserCausationHandler implements CommandHandler
{
    public function __construct(
        private EnvelopedEventBus $events,
        private SystemClock $clock,
        private MessageContextStack $stack,
        private Closure $captureCommandId,
    ) {}

    public function __invoke(RegisterUser $cmd): void
    {
        $parent = $this->stack->current()->get();
        ($this->captureCommandId)($parent->metadata->id);
        $eventMeta = $parent->metadata->forCausedMessage(
            MessageId::generate(),
            $this->clock->now(),
        );
        $this->events->publishEnveloped(new Envelope(new UserRegistered($cmd->userId), $eventMeta));
    }
}
