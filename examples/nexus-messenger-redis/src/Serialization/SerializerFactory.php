<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Serialization;

use InvalidArgumentException;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Serialization\TracingMessageSerializer;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;

/**
 * Shared serializer bootstrap for the example's bin scripts.
 *
 * Selects the message serializer from the SERIALIZER environment variable:
 *
 *   - php-native (default) — PHP serialize()/unserialize() with an explicit
 *     allow-list (prevents PHP Object Injection, CWE-502); fastest, but the
 *     wire format is PHP-only
 *   - json — ValinorMessageSerializer; human-readable, interoperable JSON
 *   - msgpack — MessagePackMessageSerializer; compact binary bodies. When an
 *     enabled Observability is passed, the serializer is wrapped in
 *     TracingMessageSerializer so every serialize/deserialize records a span
 *     and the nexus.serialization.* metrics
 */
final readonly class SerializerFactory
{
    private function __construct() {}

    /**
     * @param list<class-string> $allowedClasses
     */
    public static function fromEnvironment(
        TypeRegistry $registry,
        array $allowedClasses,
        ?Observability $observability = null,
    ): MessageSerializer {
        $format = (string) ($_SERVER['SERIALIZER'] ?? 'php-native');

        return match ($format) {
            'json' => new ValinorMessageSerializer($registry),
            'msgpack' => self::msgpack($registry, $observability),
            'php-native' => new PhpNativeSerializer(allowedClasses: $allowedClasses),
            default => throw new InvalidArgumentException(
                "Unknown SERIALIZER \"{$format}\" — expected php-native, json, or msgpack.",
            ),
        };
    }

    private static function msgpack(TypeRegistry $registry, ?Observability $observability): MessageSerializer
    {
        $serializer = new MessagePackMessageSerializer($registry);

        return $observability !== null && $observability->isEnabled()
            ? new TracingMessageSerializer($serializer, $observability)
            : $serializer;
    }
}
