<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Wallet\Domain\Command\Deposit;
use Monadial\Nexus\Example\Wallet\Domain\Command\GetBalance;
use Monadial\Nexus\Example\Wallet\Domain\Command\Withdraw;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Runtime\Duration;

/**
 * Actor-per-request orchestrator.
 *
 * Registered via `$app->perRequestActor('request', …)` — the http
 * dispatcher spawns a fresh instance for every inbound request and
 * stops it after the response is written. The actor's lifetime is
 * bounded by a single HTTP request, which makes it the right place
 * for request-scoped state (correlation ids, idempotency keys,
 * rate-limit tokens, transient retry counters).
 *
 * Wire-up flow per request:
 *   handler --ask--> RequestActor --ask--> Directory --ask--> Wallet
 *
 * Each hop uses `ask()` which stamps the temporary reply ref onto the
 * envelope; the recipient calls `$ctx->reply(...)` to resolve the
 * caller's Future.
 *
 * The directory ref arrives WITH the HandleRequest message — the HTTP
 * handler grabs both `#[FromActor('request')]` and
 * `#[FromActor('wallets')]` and threads them through.
 */
final readonly class RequestActor
{
    public static function behavior(): Behavior
    {
        return Behavior::receive(
            static function (ActorContext $ctx, HandleRequest $message): Behavior {
                $walletRef = $message->directory
                    ->ask(new EnsureWallet($message->ownerId), Duration::seconds(2))
                    ->await();

                $command = match ($message->action) {
                    'deposit' => new Deposit(new Money($message->amountCents)),
                    'withdraw' => new Withdraw(new Money($message->amountCents)),
                    'balance' => new GetBalance(),
                    default => null,
                };

                if ($command === null) {
                    return Behavior::same();
                }

                $reply = $walletRef->ref
                    ->ask($command, Duration::seconds(2))
                    ->await();

                $ctx->reply($reply);

                return Behavior::same();
            },
        );
    }
}
