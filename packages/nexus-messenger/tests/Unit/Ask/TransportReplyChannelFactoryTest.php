<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Ask;

use InvalidArgumentException;
use Monadial\Nexus\Messenger\Ask\ReplyQueueLifecycle;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannel;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannelFactory;
use Monadial\Nexus\Messenger\Tests\Support\RecordingTransportFactory;
use Monadial\Nexus\Messenger\Tests\Support\SetupRecordingTransportFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[CoversClass(TransportReplyChannelFactory::class)]
#[CoversClass(TransportReplyChannel::class)]
final class TransportReplyChannelFactoryTest extends TestCase
{
    private RecordingTransportFactory $recording;

    #[Test]
    public function createReturnsChannelWhoseReceiverIsTheBuiltTransport(): void
    {
        $factory = $this->factory('in-memory://replies-{instance}');

        $channel = $factory->create();

        self::assertCount(1, $this->recording->createdTransports);
        self::assertSame($this->recording->createdTransports[0], $channel->receiver());
    }

    #[Test]
    public function createPassesOptionsToTheTransportFactory(): void
    {
        $options = ['auto_setup' => false];
        $factory = new TransportReplyChannelFactory(
            $this->recording,
            new PhpSerializer(),
            'in-memory://replies-{instance}',
            'replies',
            ReplyQueueLifecycle::Ephemeral,
            $options,
        );

        $factory->create();

        self::assertSame([$options], $this->recording->options);
    }

    #[Test]
    public function instancePlaceholderProducesDistinctDsnsAcrossCreateCalls(): void
    {
        $factory = $this->factory('in-memory://replies-{instance}');

        $factory->create();
        $factory->create();

        self::assertCount(2, $this->recording->dsns);
        self::assertNotSame($this->recording->dsns[0], $this->recording->dsns[1]);
        self::assertMatchesRegularExpression('~^in-memory://replies-[0-9a-f]{16}$~', $this->recording->dsns[0]);
        self::assertMatchesRegularExpression('~^in-memory://replies-[0-9a-f]{16}$~', $this->recording->dsns[1]);
    }

    #[Test]
    public function namePlaceholderIsSubstitutedWithTheChannelName(): void
    {
        $factory = $this->factory('in-memory://{name}');

        $factory->create();

        self::assertSame(['in-memory://replies'], $this->recording->dsns);
    }

    #[Test]
    public function nameReturnsTheLogicalChannelName(): void
    {
        $channel = $this->factory('in-memory://{name}')->create();

        self::assertSame('replies', $channel->name());
    }

    #[Test]
    public function persistentLifecycleWithInstancePlaceholderThrows(): void
    {
        $factory = $this->factory('in-memory://replies-{instance}', ReplyQueueLifecycle::Persistent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{instance}');

        $factory->create();
    }

    #[Test]
    public function persistentLifecycleWithInstancePlaceholderCreatesNoTransport(): void
    {
        $factory = $this->factory('in-memory://replies-{instance}', ReplyQueueLifecycle::Persistent);

        try {
            $factory->create();
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $this->recording->dsns);
    }

    #[Test]
    public function ephemeralLifecycleSetsUpSetupableTransports(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies-{instance}',
            'replies',
        );

        $factory->create();

        self::assertSame(1, $setupable->createdTransports[0]->setupCalls);
    }

    #[Test]
    public function deleteOnShutdownLifecycleSetsUpSetupableTransports(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies-{instance}',
            'replies',
            ReplyQueueLifecycle::DeleteOnShutdown,
        );

        $factory->create();

        self::assertSame(1, $setupable->createdTransports[0]->setupCalls);
    }

    #[Test]
    public function persistentLifecycleSkipsSetup(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies',
            'replies',
            ReplyQueueLifecycle::Persistent,
        );

        $factory->create();

        self::assertSame(0, $setupable->createdTransports[0]->setupCalls);
    }

    #[Test]
    public function closeIsANoOpForEphemeralChannels(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies-{instance}',
            'replies',
        );

        $factory->create()->close();

        self::assertSame(0, $setupable->createdTransports[0]->resetCalls);
    }

    #[Test]
    public function closeIsANoOpForPersistentChannels(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies',
            'replies',
            ReplyQueueLifecycle::Persistent,
        );

        $factory->create()->close();

        self::assertSame(0, $setupable->createdTransports[0]->resetCalls);
    }

    #[Test]
    public function closeTearsDownDeleteOnShutdownChannelsWhenTransportSupportsIt(): void
    {
        $setupable = new SetupRecordingTransportFactory();
        $factory = new TransportReplyChannelFactory(
            $setupable,
            new PhpSerializer(),
            'test://replies-{instance}',
            'replies',
            ReplyQueueLifecycle::DeleteOnShutdown,
        );

        $factory->create()->close();

        self::assertSame(1, $setupable->createdTransports[0]->resetCalls);
    }

    #[Test]
    public function closeIsSafeForDeleteOnShutdownWhenTransportHasNoTeardown(): void
    {
        $channel = new TransportReplyChannel(
            'replies',
            $this->transportWithoutTeardown(),
            ReplyQueueLifecycle::DeleteOnShutdown,
        );

        $channel->close();

        self::assertSame('replies', $channel->name());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->recording = new RecordingTransportFactory(new InMemoryTransportFactory());
    }

    private function factory(
        string $dsnTemplate,
        ReplyQueueLifecycle $lifecycle = ReplyQueueLifecycle::Ephemeral,
    ): TransportReplyChannelFactory {
        return new TransportReplyChannelFactory(
            $this->recording,
            new PhpSerializer(),
            $dsnTemplate,
            'replies',
            $lifecycle,
        );
    }

    private function transportWithoutTeardown(): TransportInterface
    {
        return new class implements TransportInterface {
            #[Override]
            public function get(): iterable
            {
                return [];
            }

            #[Override]
            public function ack(Envelope $envelope): void
            {
                // no-op: teardown-less transport double
            }

            #[Override]
            public function reject(Envelope $envelope): void
            {
                // no-op: teardown-less transport double
            }

            #[Override]
            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };
    }
}
