<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Override;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Test factory producing SetupRecordingTransport instances.
 *
 * @implements TransportFactoryInterface<SetupRecordingTransport>
 */
final class SetupRecordingTransportFactory implements TransportFactoryInterface
{
    /** @var list<SetupRecordingTransport> */
    public array $createdTransports = [];

    #[Override]
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $transport = new SetupRecordingTransport();
        $this->createdTransports[] = $transport;

        return $transport;
    }

    #[Override]
    public function supports(string $dsn, array $options): bool
    {
        return true;
    }
}
