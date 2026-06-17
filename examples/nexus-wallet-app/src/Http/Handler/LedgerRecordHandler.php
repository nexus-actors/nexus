<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Example\Wallet\Domain\Command\RecordLedger;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /wallet/ledger/record — record one deposit/withdraw against the
 * authenticated user's ledger.
 *
 * Demonstrates the `EntityRefFactory` + `EntityBehavior` flow:
 * - Lookup (or spawn) the LedgerActor for this owner — single writer
 *   per (`WalletLedger`, ownerId).
 * - Fire-and-forget `RecordLedger` command. The actor mutates the entity
 *   and flushes inside its own dedicated EntityManager.
 *
 * Body shape: `{"kind": "deposit" | "withdraw", "amountCents": int}`.
 *
 * The factory is constructor-injected (instantiated once per worker
 * thread in `server.php`) — the wallet-app doesn't run a PSR-11
 * container, so we register this handler via a closure that captures
 * the factory rather than relying on `#[FromService]`.
 */
final readonly class LedgerRecordHandler
{
    public function __construct(private EntityRefFactory $ledgerFactory) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[FromPrincipal]
        Principal $principal,
    ): ResponseInterface {
        /** @var array{kind?: string, amountCents?: int} $body */
        $body = json_decode((string) $request->getBody(), associative: true) ?: [];
        $kind = (string) ($body['kind'] ?? 'deposit');
        $amount = (int) ($body['amountCents'] ?? 0);

        if (!in_array($kind, ['deposit', 'withdraw'], true)) {
            return JsonResponse::ok(['error' => 'kind must be deposit or withdraw'])
                ->withStatus(400);
        }

        if ($amount <= 0) {
            return JsonResponse::ok(['error' => 'amountCents must be positive'])
                ->withStatus(400);
        }

        $this->ledgerFactory->of($principal->id())->tell(new RecordLedger($kind, $amount));

        return JsonResponse::ok([
            'amountCents' => $amount,
            'kind' => $kind,
            'ownerId' => $principal->id(),
            'status' => 'recorded',
        ]);
    }
}
