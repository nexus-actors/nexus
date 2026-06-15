<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
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
 *   handler → ask(this) → ask(directory) → ask(wallet) → reply → handler
 *
 * The directory ref arrives with the HandleRequest message — the
 * handler grabs both `#[FromActor('request')]` and
 * `#[FromActor('wallets')]` and threads them through.
 */
final readonly class RequestActor
{
    public static function behavior(): Behavior
    {
        return Behavior::receive(
            static function (ActorContext $ctx, HandleRequest $message): Behavior {
                $walletRef = $message->directory->ask(
                    static fn(ActorRef $rt): EnsureWallet => new EnsureWallet($message->ownerId, $rt),
                    Duration::seconds(2),
                )->await();

                $command = match ($message->action) {
                    'deposit' => static fn(ActorRef $rt): Deposit => new Deposit(
                        new Money($message->amountCents),
                        $rt,
                    ),
                    'withdraw' => static fn(ActorRef $rt): Withdraw => new Withdraw(
                        new Money($message->amountCents),
                        $rt,
                    ),
                    'balance' => static fn(ActorRef $rt): GetBalance => new GetBalance($rt),
                    default => null,
                };

                if ($command === null) {
                    return Behavior::same();
                }

                $reply = $walletRef->ref->ask($command, Duration::seconds(2))->await();
                $message->replyTo->tell($reply);

                return Behavior::same();
            },
        );
    }
}
