<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use CuyZ\Valinor\Mapper\TreeMapper;
use JsonException;
use JsonSerializable;
use Monadial\Nexus\Http\Rejection\BodyParseException;
use Override;
use Throwable;

use function get_object_vars;
use function is_object;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final readonly class JsonValinorMarshaller implements Marshaller
{
    public function __construct(private TreeMapper $mapper) {}

    #[Override]
    public function mediaType(): MediaType
    {
        return new MediaType('application', 'json');
    }

    /**
     * @template T
     * @param class-string<T> $targetType
     * @return T
     */
    #[Override]
    public function unmarshal(string $body, string $targetType): mixed
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BodyParseException('invalid JSON: ' . $e->getMessage());
        }

        try {
            return $this->mapper->map($targetType, $decoded);
        } catch (Throwable $e) {
            throw new BodyParseException('JSON does not match ' . $targetType . ': ' . $e->getMessage());
        }
    }

    #[Override]
    public function marshal(mixed $value): string
    {
        if (is_object($value) && ! $value instanceof JsonSerializable) {
            $value = get_object_vars($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
