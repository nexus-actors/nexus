<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

/**
 * @psalm-api
 *
 * Sugar for common ResponseInterface shapes. Returns nyholm/psr7 Response
 * instances so consumers can keep calling withStatus/withHeader/etc.
 */
final class Response
{
    public static function ok(): ResponseInterface
    {
        return new Psr7Response(200);
    }

    public static function noContent(): ResponseInterface
    {
        return new Psr7Response(204);
    }

    public static function created(?string $location = null): ResponseInterface
    {
        $headers = $location !== null
            ? ['Location' => $location]
            : [];

        return new Psr7Response(201, $headers);
    }

    public static function notFound(string $message = 'Not Found'): ResponseInterface
    {
        return new Psr7Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    public static function badRequest(string $message = 'Bad Request'): ResponseInterface
    {
        return new Psr7Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    public static function gatewayTimeout(): ResponseInterface
    {
        return new Psr7Response(504);
    }

    public static function serviceUnavailable(?Duration $retryAfter = null): ResponseInterface
    {
        $headers = [];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter->toSeconds();
        }

        return new Psr7Response(503, $headers);
    }

    public static function internalServerError(): ResponseInterface
    {
        return new Psr7Response(500);
    }
}
