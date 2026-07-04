<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\Http;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

#[RequiresRole('ops')]
final readonly class RestockHandler
{
    /**
     * @psalm-suppress NoValue -- ask() on ActorRef<object> returns Future<object>; the reply
     *                            is StockCommandAccepted (Restock never rejects) but the ask
     *                            channel is untyped, so the default arm stays reachable.
     */
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        Sku $sku,
        InventoryRefFactory $inventory,
        #[FromBody]
        RestockRequest $body,
    ): ResponseInterface {
        try {
            $tenant = TenantId::fromString((string) ($principal->claims()['tenant'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        $reply = $inventory->of($tenant, $sku)
            ->ask(new Restock($tenant, $sku, $body->quantity), Duration::seconds(2))
            ->await();

        return match (true) {
            $reply instanceof StockCommandAccepted => JsonResponse::ok(
                new InventoryResource($reply->sku, $reply->onHand, $reply->available),
            ),
            default => Response::internalServerError(),
        };
    }
}
