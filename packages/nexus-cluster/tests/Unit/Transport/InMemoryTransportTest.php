<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Transport;

use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryTransport::class)]
final class InMemoryTransportTest extends TestCase
{
    #[Test]
    public function sendRecordsMessages(): void
    {
        $transport = new InMemoryTransport();

        $transport->send(3, 'hello-worker-3');
        $transport->send(7, 'hello-worker-7');

        $sent = $transport->getSent();
        self::assertCount(2, $sent);
        self::assertSame(3, $sent[0]['targetWorker']);
        self::assertSame('hello-worker-3', $sent[0]['data']);
        self::assertSame(7, $sent[1]['targetWorker']);
    }

    #[Test]
    public function receiveTriggersListener(): void
    {
        $transport = new InMemoryTransport();
        $received = [];

        $transport->listen(static function (string $data) use (&$received): void {
            $received[] = $data;
        });

        $transport->receive('message-1');
        $transport->receive('message-2');

        self::assertCount(2, $received);
        self::assertSame('message-1', $received[0]);
        self::assertSame('message-2', $received[1]);
    }

    #[Test]
    public function receiveWithoutListenerDoesNothing(): void
    {
        $transport = new InMemoryTransport();

        // Should not throw
        $transport->receive('orphan-message');

        self::assertTrue(true);
    }

    #[Test]
    public function closeStopsListening(): void
    {
        $transport = new InMemoryTransport();
        $received = [];

        $transport->listen(static function (string $data) use (&$received): void {
            $received[] = $data;
        });

        $transport->receive('before-close');
        $transport->close();
        $transport->receive('after-close');

        self::assertCount(1, $received);
    }

    #[Test]
    public function getSentToFiltersByWorker(): void
    {
        $transport = new InMemoryTransport();

        $transport->send(1, 'a');
        $transport->send(2, 'b');
        $transport->send(1, 'c');

        self::assertSame(['a', 'c'], $transport->getSentTo(1));
        self::assertSame(['b'], $transport->getSentTo(2));
        self::assertSame([], $transport->getSentTo(99));
    }
}
