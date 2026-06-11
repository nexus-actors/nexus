<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Throwable;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * @psalm-api
 *
 * Base for HTTP-aware exceptions. The status code is used directly
 * by the default exception mapper.
 */
abstract class HttpException extends NexusException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new GenericHttpException(404, $message);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new GenericHttpException(401, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new GenericHttpException(403, $message);
    }

    /** @param array<string, string> $errors */
    public static function unprocessableEntity(array $errors): self
    {
        return new GenericHttpException(
            422,
            json_encode(['errors' => $errors], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new GenericHttpException(409, $message);
    }
}
