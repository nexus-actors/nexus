<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Messenger\Event\MessagePublished;
use Monadial\Nexus\Messenger\Producer\MessengerGateway;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingDispatcher;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Observability\Trace\SpanKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MessengerGateway::class)]
final class MessengerGatewayTest extends TestCase
{
    #[Test]
    public function publishSendsTheMessageWithGivenStamps(): void
    {
        $sender = new RecordingSender();
        $gateway = new MessengerGateway($sender);
        $message = new stdClass();
        $stamp = new TargetActorPathStamp('/user/orders');

        $gateway->publish($message, [$stamp]);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        self::assertSame($stamp, $sender->sent[0]->last(TargetActorPathStamp::class));
    }

    #[Test]
    public function publishRecordsSpanAndCounterAndDispatchesMessagePublished(): void
    {
        $sender = new RecordingSender();
        $observability = new RecordingObservability();
        $dispatcher = new RecordingDispatcher();
        $gateway = new MessengerGateway($sender, $observability, $dispatcher);
        $message = new stdClass();

        $gateway->publish($message);

        self::assertCount(1, $sender->sent);
        self::assertCount(1, $observability->tracer->spans);

        $span = $observability->tracer->spans[0];

        self::assertSame('messenger.send', $span->name);
        self::assertSame(SpanKind::Producer, $span->kind);
        self::assertSame([
            'messaging.operation' => 'send',
            'messaging.system' => 'symfony-messenger',
            'nexus.message.type' => stdClass::class,
            'nexus.messenger.sender' => 'gateway',
        ], $span->attributes);
        self::assertTrue($span->ended);
        self::assertSame(1, $observability->meter->sum('nexus.messenger.messages.sent'));
        self::assertCount(1, $dispatcher->events);

        $event = $dispatcher->events[0];

        self::assertInstanceOf(MessagePublished::class, $event);
        self::assertSame($message, $event->message);
        self::assertSame('gateway', $event->senderName);
    }

    #[Test]
    public function publishAttachesTraceContextStampWhenObservabilityIsEnabled(): void
    {
        $sender = new RecordingSender();
        $gateway = new MessengerGateway($sender, new RecordingObservability());

        $gateway->publish(new stdClass());

        $stamp = $sender->sent[0]->last(TraceContextStamp::class);

        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        self::assertIsArray($stamp->carrier);
    }
}
