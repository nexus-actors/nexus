<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class RecordingSender implements SenderInterface
{
    /** @var list<Envelope> */
    public array $sent = [];

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope;

        return $envelope;
    }
}
