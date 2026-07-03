<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use __PHP_Incomplete_Class;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;
use Override;
use Throwable;

use function serialize;
use function unserialize;

/**
 * @psalm-api
 *
 * Serializer using PHP's native serialize()/unserialize().
 *
 * SECURITY: unserialize() can instantiate arbitrary classes and trigger their
 * __wakeup()/__destruct() gadgets (PHP Object Injection, CWE-502). By default
 * this serializer allows any class so it can round-trip rich object graphs
 * (events with nested value objects, enums, DateTimeImmutable, ...), which is
 * safe ONLY for trusted data the application serialized itself.
 *
 * When decoding data that could cross a trust boundary, pass an explicit
 * allow-list of permitted classes — e.g. every registered message/event class:
 *
 *   new PhpNativeSerializer(allowedClasses: $registry->allClasses());
 *
 * Any class outside the list (at any depth of the graph) is refused instead of
 * instantiated. Note the allow-list must include every nested class in the
 * graph, not just the top-level type. For untrusted input prefer a
 * schema-based codec such as ValinorMessageSerializer.
 */
final readonly class PhpNativeSerializer implements MessageSerializer
{
    /**
     * @param list<class-string>|null $allowedClasses null = allow any class
     *        (trusted data only); a list restricts instantiation to those
     *        classes and rejects everything else.
     */
    public function __construct(private ?array $allowedClasses = null) {}

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

        if ($result instanceof __PHP_Incomplete_Class) {
            throw new MessageDeserializationException(
                $type,
                'Payload contained a class not permitted for deserialization',
            );
        }

        if (!$result instanceof $type) {
            throw new MessageDeserializationException(
                $type,
                'Expected instance of ' . $type . ', got ' . $result::class,
            );
        }

        return $result;
    }
}
