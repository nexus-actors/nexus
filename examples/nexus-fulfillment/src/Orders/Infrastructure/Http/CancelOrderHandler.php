<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

use function json_encode;

final readonly class CancelOrderHandler
{
    public function __construct(private OrderRefFactory $orders) {}

    /**
     * @psalm-suppress NoValue -- ask() on ActorRef<object> returns Future<object>; reply
     *                            type is OrderAccepted|OrderRejected (union), not expressible
     *                            via #[ReplyType] which supports only a single class.
     */
    public function __invoke(
        #[FromPrincipal]
        Principal $principal,
        OrderId $id,
    ): ResponseInterface {
        if (!$principal->hasRole('ops')) {
            return new Psr7Response(403, ['Content-Type' => 'application/json'], '{"error":"role ops required"}');
        }

        try {
            $tenant = TenantId::fromString((string) ($principal->claims()['tenant'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        $reply = $this->orders->of($tenant, $id)
            ->ask(new CancelOrder($tenant, $id, 'api cancel'), Duration::seconds(2))
            ->await();

        return match (true) {
            $reply instanceof OrderAccepted => JsonResponse::ok(OrderActionResource::fromReply($reply)),
            $reply instanceof OrderRejected => new Psr7Response(
                409,
                ['Content-Type' => 'application/json'],
                json_encode(['orderId' => $reply->orderId->value, 'reason' => $reply->reason], JSON_THROW_ON_ERROR),
            ),
            default => Response::internalServerError(),
        };
    }
}
