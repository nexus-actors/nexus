<?php

declare(strict_types=1);

namespace App\Actor;

use App\Actor\Message\Poll;
use App\Entity\Order;
use App\Message\PlaceOrder;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[Actor(ActorType::Shared, 'order-processor')]
final class OrderProcessorActor extends AbstractActor
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly EntityManagerInterface $em,
        private readonly TransportInterface $transport,
    ) {}

    public function onPreStart(ActorContext $ctx): void
    {
        $ctx->scheduleRepeatedly(Duration::zero(), Duration::seconds(1), new Poll());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if (!$message instanceof Poll) {
            return Behavior::unhandled();
        }

        foreach ($this->transport->get() as $envelope) {
            $inner = $envelope->getMessage();

            if (!$inner instanceof PlaceOrder) {
                continue;
            }

            $order = new Order(new Ulid(), $inner->customerId, $inner->productId, $inner->qty);
            $this->em->persist($order);
            $this->em->flush();
            $this->cache->invalidateTags(['inventory']);
            $this->transport->ack($envelope);
        }

        return Behavior::same();
    }
}
