<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Idempotency;

use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey as IdempotencyKeyAttribute;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use ReflectionClass;
use ReflectionObject;

/**
 * @psalm-api
 *
 * Three-tier resolution order per umbrella spec §13.2.1 + §13.4:
 *   1. `#[IdempotencyKey(field:)]` attribute on the message class — read the
 *      named property's value from the envelope's message and lift it to a
 *      `IdempotencyKey` value object.
 *   2. `MessageMetadata::$headers[HeaderKeys::IDEMPOTENCY_KEY]` if present.
 *   3. Fall back to the framework-assigned `messageId.value()` — guarantees
 *      every message carries a key even without application opt-in.
 */
final class IdempotencyKeyResolver
{
    /**
     * @psalm-suppress MixedAssignment — `ReflectionProperty::getValue` returns mixed by design.
     */
    public function resolve(Envelope $envelope): IdempotencyKey
    {
        $message = $envelope->message;
        $attrs = new ReflectionClass($message::class)->getAttributes(IdempotencyKeyAttribute::class);

        if ($attrs !== []) {
            $attribute = $attrs[0]->newInstance();
            $value = new ReflectionObject($message)->getProperty($attribute->field)->getValue($message);

            return new IdempotencyKey((string) $value);
        }

        $headerValue = $envelope->metadata->headers->get(HeaderKeys::IDEMPOTENCY_KEY);

        if ($headerValue->isSome()) {
            return new IdempotencyKey((string) $headerValue->getUnsafe());
        }

        return new IdempotencyKey($envelope->metadata->id->value());
    }
}
