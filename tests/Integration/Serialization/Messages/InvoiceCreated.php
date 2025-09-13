<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('invoice.created')]
final readonly class InvoiceCreated
{
    public function __construct(public string $invoiceId, public Money $total,) {}
}
