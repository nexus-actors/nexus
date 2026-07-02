<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * @psalm-api
 *
 * JSON response sugar. JSON_UNESCAPED_SLASHES is the default since slashes
 * appear constantly in URLs and look ugly when escaped.
 */
final class JsonResponse
{
    public const int DEFAULT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function ok(mixed $data, int $flags = self::DEFAULT_FLAGS): ResponseInterface
    {
        return self::build(200, $data, $flags);
    }

    public static function created(
        mixed $data,
        ?string $location = null,
        int $flags = self::DEFAULT_FLAGS,
    ): ResponseInterface {
        $response = self::build(201, $data, $flags);

        if ($location !== null) {
            $response = $response->withHeader('Location', $location);
        }

        return $response;
    }

    /**
     * @psalm-suppress PossiblyFalseArgument JSON_THROW_ON_ERROR guarantees non-false return.
     */
    private static function build(int $status, mixed $data, int $flags): ResponseInterface
    {
        $body = json_encode($data, $flags | JSON_THROW_ON_ERROR);

        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            $body,
        );
    }
}
