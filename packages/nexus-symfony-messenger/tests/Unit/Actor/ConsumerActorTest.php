<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Symfony\Messenger\Actor\ConsumerActor;
use Monadial\Nexus\Symfony\Messenger\Message\ConsumeFromTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[CoversClass(ConsumerActor::class)]
final class ConsumerActorTest extends TestCase
{
    #[Test]
    public function handleReturnsUnhandledForUnknownMessage(): void
    {
        $bus  = $this->createStub(MessageBusInterface::class);
        $ctx  = $this->createStub(ActorContext::class);

        $actor  = new ConsumerActor($bus, []);
        $result = $actor->handle($ctx, new \stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $result);
    }

    #[Test]
    public function handleConsumeFromTransportReturnsSame(): void
    {
        $bus       = $this->createStub(MessageBusInterface::class);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('get')->willReturn([]);

        $ctx = $this->createStub(ActorContext::class);

        $actor  = new ConsumerActor($bus, ['async' => $transport]);
        $result = $actor->handle($ctx, new ConsumeFromTransport('async', 10));

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function handleConsumeWithUnknownTransportReturnsSame(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $ctx = $this->createStub(ActorContext::class);

        $actor  = new ConsumerActor($bus, []);
        $result = $actor->handle($ctx, new ConsumeFromTransport('missing', 10));

        self::assertInstanceOf(SameBehavior::class, $result);
    }
}
