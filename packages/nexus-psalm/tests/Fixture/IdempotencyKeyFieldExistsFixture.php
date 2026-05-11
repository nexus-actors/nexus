<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey;
use Monadial\Nexus\Ddd\Messaging\Message\Command;

/**
 * Good: field 'clientRequestId' exists and is string-typed.
 *
 * @psalm-immutable
 */
#[IdempotencyKey(field: 'clientRequestId')]
final readonly class IdempKeyGoodCommand implements Command
{
    public function __construct(public string $clientRequestId, public string $payload) {}
}

/**
 * Bad: field 'missingField' does not exist on the class.
 *
 * @psalm-immutable
 */
#[IdempotencyKey(field: 'missingField')]
final readonly class IdempKeyBadMissingCommand implements Command
{
    public function __construct(public string $payload) {}
}

/**
 * Bad: field 'orderId' exists but is typed `int`, not `string`.
 *
 * @psalm-immutable
 */
#[IdempotencyKey(field: 'orderId')]
final readonly class IdempKeyBadTypeCommand implements Command
{
    public function __construct(public int $orderId) {}
}
