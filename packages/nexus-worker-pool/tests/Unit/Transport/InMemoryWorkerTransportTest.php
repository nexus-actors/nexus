<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit\Transport;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\WorkerPool\Tests\Unit\Support\Ping;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryWorkerTransport::class)]
final class InMemoryWorkerTransportTest extends TestCase
{
    #[Test]
    public function sendRecordsEnvelope(): void
    {
        $transport = new InMemoryWorkerTransport();
        $envelope = Envelope::of(new Ping(), ActorPath::root(), ActorPath::root());

        $transport->send(2, $envelope);

        $sent = $transport->getSentTo(2);
        self::assertCount(1, $sent);
        self::assertSame($envelope, $sent[0]);
    }

    #[Test]
    public function receiveTriggersListener(): void
    {
        $transport = new InMemoryWorkerTransport();
        $received = [];
        $transport->listen(static function (Envelope $env) use (&$received): void {
            $received[] = $env;
        });

        $envelope = Envelope::of(new Ping(), ActorPath::root(), ActorPath::root());
        $transport->receive($envelope);

        self::assertCount(1, $received);
    }
}
