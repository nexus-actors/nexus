<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;

/**
 * Serializer using JSON encoding and Valinor for type-safe deserialization.
 *
 * Messages are serialized to JSON using json_encode on their public properties.
 * Deserialization uses Valinor's mapper for strict type reconstruction.
 */
final readonly class ValinorMessageSerializer implements MessageSerializer
{
    private TreeMapper $mapper;

    public function __construct(
        private TypeRegistry $registry,
        ?MapperBuilder $mapperBuilder = null,
    ) {
        $this->mapper = ($mapperBuilder ?? new MapperBuilder())
            ->allowPermissiveTypes()
            ->mapper();
    }

    /**
     * @throws MessageSerializationException
     */
    #[\NoDiscard]
    public function serialize(object $message): string
    {
        $className = $message::class;
        $typeName = $this->registry->nameForClass($className);

        if ($typeName->isNone()) { // @phpstan-ignore method.impossibleType
            throw new MessageSerializationException(
                $className,
                "No type name registered for class '{$className}'",
            );
        }

        try {
            $json = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MessageSerializationException($className, $e->getMessage(), $e);
        }

        return $json;
    }

    /**
     * @throws MessageDeserializationException
     */
    #[\NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        $className = $this->registry->classForName($type);

        if ($className->isNone()) { // @phpstan-ignore method.impossibleType
            throw new MessageDeserializationException(
                $type,
                "No class registered for type name '{$type}'",
            );
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        try {
            /** @var object $result */
            $result = $this->mapper->map($className->get(), $decoded);
        } catch (\Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        return $result;
    }
}
