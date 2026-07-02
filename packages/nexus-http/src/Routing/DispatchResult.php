<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Routing;

/**
 * @psalm-api
 */
final readonly class DispatchResult
{
    public const int FOUND = 1;
    public const int NOT_FOUND = 2;
    public const int METHOD_NOT_ALLOWED = 3;

    /**
     * @param self::FOUND|self::NOT_FOUND|self::METHOD_NOT_ALLOWED $status
     * @param array<string, string> $pathParams
     * @param list<string> $allowedMethods
     */
    public function __construct(
        public int $status,
        public ?Route $route,
        public array $pathParams,
        public array $allowedMethods,
    ) {}

    /** @param array<string, string> $params */
    public static function found(Route $route, array $params): self
    {
        return new self(self::FOUND, $route, $params, []);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowed);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, [], []);
    }
}
