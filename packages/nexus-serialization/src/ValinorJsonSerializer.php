<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use JsonException;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;
use Override;
use Throwable;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-api
 *
 * Direct-class JSON serializer using Valinor. Unlike {@see ValinorMessageSerializer},
 * this one takes the target class FQCN directly — no `TypeRegistry` lookup —
 * so it's the right fit for HTTP body decoding via `#[FromBody]`, where the
 * param's class type IS the target.
 *
 * Encode uses `json_encode` on public readonly properties. Decode uses
 * Valinor's mapper for strict type reconstruction (constructor promotion,
 * variant unions, enum coercion, etc).
 */
final readonly class ValinorJsonSerializer implements MessageSerializer
{
    private TreeMapper $mapper;

    public function __construct(?MapperBuilder $mapperBuilder = null)
    {
        $this->mapper = ($mapperBuilder ?? new MapperBuilder())
            ->allowPermissiveTypes()
            ->mapper();
    }

    /**
     * @throws MessageSerializationException
     */
    #[Override]
    #[NoDiscard]
    public function serialize(object $message): string
    {
        try {
            return json_encode($message, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
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
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        try {
            /** @var object $result */
            $result = $this->mapper->map($type, $decoded ?? []);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        return $result;
    }
}
