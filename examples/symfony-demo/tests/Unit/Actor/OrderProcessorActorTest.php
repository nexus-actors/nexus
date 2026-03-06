<?php

declare(strict_types=1);

namespace App\Tests\Unit\Actor;

use App\Actor\Message\Poll;
use App\Actor\OrderProcessorActor;
use App\Message\PlaceOrder;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[CoversClass(OrderProcessorActor::class)]
final class OrderProcessorActorTest extends TestCase
{
    #[Test]
    public function onPreStart_schedulesPolling(): void
    {
        $em        = $this->createStub(EntityManagerInterface::class);
        $cache     = $this->createStub(TagAwareCacheInterface::class);
        $transport = $this->createStub(TransportInterface::class);

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())
            ->method('scheduleRepeatedly')
            ->with(
                Duration::zero(),
                Duration::seconds(1),
                self::isInstanceOf(Poll::class),
            );

        $actor = new OrderProcessorActor($cache, $em, $transport);
        $actor->onPreStart($ctx);
    }

    #[Test]
    public function handlePoll_withNoEnvelopes_returnsSame(): void
    {
        $em        = $this->createStub(EntityManagerInterface::class);
        $cache     = $this->createStub(TagAwareCacheInterface::class);
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('get')->willReturn([]);

        $ctx      = $this->createStub(ActorContext::class);
        $actor    = new OrderProcessorActor($cache, $em, $transport);
        $behavior = $actor->handle($ctx, new Poll());

        self::assertSame(Behavior::same(), $behavior);
    }

    #[Test]
    public function handlePoll_persistsOrderAndInvalidatesCache(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(self::once())->method('invalidateTags')->with(['inventory']);

        $envelope  = new Envelope(new PlaceOrder('cust-1', 'chair-001', 2));
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('get')->willReturn([$envelope]);
        $transport->expects(self::once())->method('ack')->with($envelope);

        $ctx   = $this->createStub(ActorContext::class);
        $actor = new OrderProcessorActor($cache, $em, $transport);
        $actor->handle($ctx, new Poll());
    }

    #[Test]
    public function handleUnknownMessage_returnsUnhandled(): void
    {
        $em        = $this->createStub(EntityManagerInterface::class);
        $cache     = $this->createStub(TagAwareCacheInterface::class);
        $transport = $this->createStub(TransportInterface::class);
        $ctx       = $this->createStub(ActorContext::class);

        $actor    = new OrderProcessorActor($cache, $em, $transport);
        $behavior = $actor->handle($ctx, new \stdClass());

        self::assertSame(Behavior::unhandled(), $behavior);
    }
}
