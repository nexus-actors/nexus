<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit\Transport;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\WorkerPool\Swoole\Transport\ThreadQueueTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Thread\Queue;

#[CoversClass(ThreadQueueTransport::class)]
final class ThreadQueueTransportTest extends TestCase
{
    #[Test]
    public function sendPushesEnvelopeToTargetQueue(): void
    {
        $queues = [0 => new Queue(), 1 => new Queue()];
        $transport = new ThreadQueueTransport($queues, workerId: 0);

        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::root());
        $transport->send(1, $envelope);

        // Pop from target queue to verify delivery
        $delivered = $queues[1]->pop(0);
        self::assertNotNull($delivered);
        self::assertEquals($envelope->requestId, $delivered->requestId);
    }
}
