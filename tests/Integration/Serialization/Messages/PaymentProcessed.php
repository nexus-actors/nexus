<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('payment.processed')]
final readonly class PaymentProcessed
{
    public function __construct(
        public string $paymentId,
        public float $amount,
        public string $currency,
        public string $status,
    ) {
    }
}
