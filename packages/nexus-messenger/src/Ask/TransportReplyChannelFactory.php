<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Ask;

use InvalidArgumentException;
use Override;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function bin2hex;
use function random_bytes;
use function str_contains;
use function strtr;

/**
 * Builds reply channels from a DSN template via a Messenger transport factory.
 *
 * The template supports two placeholders: `{instance}` (replaced with a fresh
 * random id on every create() call) and `{name}` (replaced with the logical
 * channel name). For Ephemeral and DeleteOnShutdown lifecycles the transport
 * is set up eagerly when it implements SetupableTransportInterface. The
 * Persistent lifecycle targets a pre-provisioned queue: the template must not
 * contain `{instance}` and setup() is skipped.
 *
 * WARNING — Persistent lifecycle: all instances sharing the queue compete for
 * replies. Use it only with a single consumer per channel name, otherwise
 * replies are delivered to arbitrary competing instances.
 *
 * Example:
 * ```php
 * $factory = new TransportReplyChannelFactory(
 *     $amqpFactory,
 *     $serializer,
 *     'amqp://broker/replies-{name}-{instance}?queue[ttl]=60000',
 *     'orders',
 * );
 * $channel = $factory->create();
 * ```
 *
 * @psalm-api
 */
final readonly class TransportReplyChannelFactory implements ReplyChannelFactory
{
    /**
     * @param TransportFactoryInterface<TransportInterface> $transportFactory
     * @param array<string, mixed> $options passed to createTransport
     */
    public function __construct(
        private TransportFactoryInterface $transportFactory,
        private SerializerInterface $serializer,
        private string $dsnTemplate,
        private string $channelName,
        private ReplyQueueLifecycle $lifecycle = ReplyQueueLifecycle::Ephemeral,
        private array $options = [],
    ) {
    }

    /**
     * @throws InvalidArgumentException when Persistent lifecycle is combined with an {instance} placeholder
     */
    #[Override]
    public function create(): ReplyChannel
    {
        if ($this->lifecycle === ReplyQueueLifecycle::Persistent && str_contains($this->dsnTemplate, '{instance}')) {
            throw new InvalidArgumentException(
                'Persistent reply queues must not use the {instance} placeholder: ' .
                'the queue is pre-provisioned and shared, a per-instance DSN would never be reused.',
            );
        }

        $dsn = strtr($this->dsnTemplate, [
            '{instance}' => bin2hex(random_bytes(8)),
            '{name}' => $this->channelName,
        ]);

        $transport = $this->transportFactory->createTransport($dsn, $this->options, $this->serializer);

        if ($this->lifecycle !== ReplyQueueLifecycle::Persistent && $transport instanceof SetupableTransportInterface) {
            $transport->setup();
        }

        return new TransportReplyChannel($this->channelName, $transport, $this->lifecycle);
    }
}
