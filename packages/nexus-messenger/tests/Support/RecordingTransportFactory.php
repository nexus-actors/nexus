<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Override;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Test decorator recording every DSN and options array passed to createTransport().
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    /** @var list<string> */
    public array $dsns = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /** @var list<TransportInterface> */
    public array $createdTransports = [];

    /**
     * @param TransportFactoryInterface<TransportInterface> $inner
     */
    public function __construct(private readonly TransportFactoryInterface $inner)
    {
    }

    #[Override]
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $this->dsns[] = $dsn;
        /** @var array<string, mixed> $options */
        $this->options[] = $options;

        $transport = $this->inner->createTransport($dsn, $options, $serializer);
        $this->createdTransports[] = $transport;

        return $transport;
    }

    #[Override]
    public function supports(string $dsn, array $options): bool
    {
        return $this->inner->supports($dsn, $options);
    }
}
