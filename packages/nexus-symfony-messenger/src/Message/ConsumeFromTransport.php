<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Message;

/**
 * Message sent to ConsumerActor to poll a named transport.
 *
 * @psalm-api
 */
readonly class ConsumeFromTransport
{
    public function __construct(
        public string $transportName,
        public int $limit,
    ) {}
}
