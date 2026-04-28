<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

interface Marshaller
{
    public function mediaType(): MediaType;

    /**
     * @template T
     * @param class-string<T> $targetType
     * @return T
     */
    public function unmarshal(string $body, string $targetType): mixed;

    public function marshal(mixed $value): string;
}
