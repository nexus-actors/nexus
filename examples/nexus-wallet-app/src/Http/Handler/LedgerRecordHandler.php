<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Example\Wallet\Domain\Command\RecordLedger;
use Monadial\Nexus\Example\Wallet\Http\Request\LedgerRecordRequest;
use Monadial\Nexus\Example\Wallet\Http\Response\LedgerRecordResponse;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /wallet/ledger/record — record one deposit/withdraw against the
 * authenticated user's ledger.
 *
 * Demonstrates the `EntityRefFactory` + `EntityBehavior` flow:
 *   - Lookup (or spawn) the LedgerActor for this owner — single writer
 *     per (`WalletLedger`, ownerId).
 *   - Fire-and-forget `RecordLedger` command. The actor mutates the entity
 *     and flushes inside its own dedicated EntityManager.
 *
 * The body is decoded via `#[FromBody]` + Valinor into a typed
 * {@see LedgerRecordRequest} (with `kind` as a `LedgerKind` enum) — no
 * raw `json_decode`, no array access, no string-matching.
 */
final readonly class LedgerRecordHandler
{
    public function __construct(private EntityRefFactory $ledgerFactory) {}

    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        #[FromBody]
        LedgerRecordRequest $body,
    ): ResponseInterface {
        if ($body->amountCents <= 0) {
            return Response::badRequest('amountCents must be a positive integer');
        }

        $this->ledgerFactory->of($principal->id())->tell(new RecordLedger($body->kind, $body->amountCents));

        return JsonResponse::ok(new LedgerRecordResponse(
            ownerId: $principal->id(),
            kind: $body->kind,
            amountCents: $body->amountCents,
        ));
    }
}
