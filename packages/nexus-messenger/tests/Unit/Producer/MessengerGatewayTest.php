<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Messenger\Producer\MessengerGateway;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
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
}
