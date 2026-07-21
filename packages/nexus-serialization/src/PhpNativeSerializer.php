<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use __PHP_Incomplete_Class;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;
use Override;
use Throwable;

use function get_mangled_object_vars;
use function is_array;
use function is_object;
use function serialize;
use function spl_object_id;
use function unserialize;

/**
 * @psalm-api
 *
 * Serializer using PHP's native serialize()/unserialize().
 *
 * SECURITY: unserialize() can instantiate arbitrary classes and trigger their
 * __wakeup()/__destruct() gadgets (PHP Object Injection, CWE-502). This
 * serializer therefore REQUIRES an explicit allow-list of permitted classes:
 * anything outside the list — at any depth of the object graph — is refused
 * instead of instantiated, and graphs containing refused nested objects are
 * rejected as a whole.
 *
 *   new PhpNativeSerializer(allowedClasses: $registry->allClasses());
 *
 * The allow-list must include every nested class in the graph (value objects,
 * enums, DateTimeImmutable, ...), not just the top-level type.
 *
 * Deserializing arbitrary graphs without a list is an explicit opt-in for
 * data the application serialized itself and that never crossed a trust
 * boundary: {@see PhpNativeSerializer::forTrustedData()}. For untrusted input
 * prefer a schema-based codec such as ValinorMessageSerializer.
 */
final readonly class PhpNativeSerializer implements MessageSerializer
{
    /**
     * @param list<class-string>|null $allowedClasses Classes permitted to be
     *        instantiated during deserialization, at any depth of the graph.
     *        Pass the explicit allow-list; use {@see forTrustedData()} instead
     *        of null for the allow-any trusted-data opt-in.
     */
    public function __construct(private ?array $allowedClasses) {}

    /**
     * Allow-any deserialization for data that never crossed a trust boundary.
     *
     * Explicit opt-in: only use when the application itself produced the
     * serialized bytes and stores them where no attacker or operator can
     * influence rows (CWE-502 — gadget chains become RCE otherwise).
     */
    public static function forTrustedData(): self
    {
        return new self(null);
    }

    /**
     * @throws MessageSerializationException
     */
    #[Override]
    #[NoDiscard]
    public function serialize(object $message): string
    {
        try {
            return serialize($message);
        } catch (Throwable $e) {
            throw new MessageSerializationException($message::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws MessageDeserializationException
     */
    #[Override]
    #[NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        $options = $this->allowedClasses === null
            ? []
            : ['allowed_classes' => $this->allowedClasses];

        try {
            $result = @unserialize($data, $options);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        if ($result === false) {
            throw new MessageDeserializationException($type, 'Failed to unserialize data');
        }

        if (!is_object($result)) {
            throw new MessageDeserializationException($type, 'Unserialized data is not an object');
        }

        // Refused classes surface as __PHP_Incomplete_Class stubs — at the top
        // level AND nested anywhere in the graph. Rejecting the whole graph
        // keeps the allow-list airtight: no half-materialized payload reaches
        // recovery or message handlers.
        $this->assertGraphFullyMaterialized($result, $type, []);

        if (!$result instanceof $type) {
            throw new MessageDeserializationException(
                $type,
                'Expected instance of ' . $type . ', got ' . $result::class,
            );
        }

        return $result;
    }

    /**
     * @param array<int, true> $seen Visited object ids (cycle guard).
     * @throws MessageDeserializationException
     */
    private function assertGraphFullyMaterialized(mixed $value, string $type, array $seen): void
    {
        if (is_array($value)) {
            /** @var mixed $item */
            foreach ($value as $item) {
                $this->assertGraphFullyMaterialized($item, $type, $seen);
            }

            return;
        }

        if (!is_object($value)) {
            return;
        }

        if ($value instanceof __PHP_Incomplete_Class) {
            throw new MessageDeserializationException(
                $type,
                'Payload contained a class not permitted for deserialization',
            );
        }

        $id = spl_object_id($value);

        if (isset($seen[$id])) {
            return;
        }

        $seen[$id] = true;

        /** @var mixed $property */
        foreach (get_mangled_object_vars($value) as $property) {
            $this->assertGraphFullyMaterialized($property, $type, $seen);
        }
    }
}
