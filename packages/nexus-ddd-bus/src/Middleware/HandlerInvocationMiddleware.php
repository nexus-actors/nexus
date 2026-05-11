<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;

/**
 * @psalm-api
 *
 * Pipeline stage 9 — invokes the resolved command handler. The
 * `CommandHandlerLocator::locate` method itself throws
 * `HandlerNotFoundException` (a `TerminalFailure`) when no handler is
 * registered for the message's concrete class; this middleware simply
 * threads the call through so the upstream classification rules apply.
 *
 * Handlers are invokable per `CommandHandler`'s interface contract:
 * `public function __invoke(ConcreteCommand $command): void` (or with an
 * optional `MessageContext` second parameter — validated upstream by the
 * `nexus-psalm` `CommandHandlerSignatureRule`).
 *
 * @template TIn of Command
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class HandlerInvocationMiddleware implements Middleware
{
    public function __construct(private readonly CommandHandlerLocator $locator) {}

    /**
     * @throws HandlerNotFoundException when no handler is registered.
     *
     * @psalm-suppress InvalidFunctionCall
     *   `CommandHandler` is a marker interface; concrete handlers implement
     *   `__invoke` per the contract validated by the Psalm plugin's
     *   `CommandHandlerSignatureRule`.
     */
    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        $handler = $this->locator->locate($envelope->message);
        $handler($envelope->message);

        return $next($envelope);
    }
}
