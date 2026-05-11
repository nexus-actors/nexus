<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Logging;

use Monadial\Nexus\Ddd\Bus\Attribute\Sensitive;
use ReflectionObject;

/**
 * @psalm-api
 *
 * Reflects on a message and returns an array<string, mixed> with
 * #[Sensitive]-attributed properties replaced by '[REDACTED]'. Used
 * by LoggingStartMiddleware when payload-at-DEBUG is enabled.
 */
final class PayloadRedactor
{
    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment — `$property->getValue()` returns mixed by design; the redacted output array is correspondingly mixed-valued.
     */
    public function redact(object $message): array
    {
        $reflection = new ReflectionObject($message);
        $output = [];

        foreach ($reflection->getProperties() as $property) {
            $hasSensitive = $property->getAttributes(Sensitive::class) !== [];
            $output[$property->getName()] = $hasSensitive
                ? '[REDACTED]'
                : $property->getValue($message);
        }

        return $output;
    }
}
