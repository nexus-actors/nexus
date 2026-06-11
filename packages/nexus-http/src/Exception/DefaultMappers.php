<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function assert;
use function explode;

/**
 * @psalm-api
 *
 * Registers the built-in defaults. User registrations override on exact class.
 */
final class DefaultMappers
{
    public static function registerInto(ExceptionMapperRegistry $registry, ErrorMode $mode): void
    {
        $registry->register(
            HttpException::class,
            static fn(Throwable $e): ResponseInterface => self::fromHttpException($e),
        );

        $registry->register(
            AskTimeoutException::class,
            static fn(): ResponseInterface => Response::gatewayTimeout(),
        );

        $registry->register(
            MailboxOverflowException::class,
            static fn(): ResponseInterface => Response::serviceUnavailable(Duration::seconds(1)),
        );

        $registry->register(
            MailboxClosedException::class,
            static fn(): ResponseInterface => Response::serviceUnavailable(),
        );

        $registry->register(
            Throwable::class,
            static fn(Throwable $e): ResponseInterface => match ($mode) {
                ErrorMode::Development => JsonResponse::ok([
                    'class'   => $e::class,
                    'error'   => 'Internal Server Error',
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'message' => $e->getMessage(),
                    'trace'   => explode("\n", $e->getTraceAsString()),
                ])->withStatus(500),
                ErrorMode::Production => JsonResponse::ok(['error' => 'Internal Server Error'])->withStatus(500),
            },
        );
    }

    private static function fromHttpException(Throwable $e): ResponseInterface
    {
        assert($e instanceof HttpException);

        return new Psr7Response($e->status, $e->headers, $e->getMessage());
    }
}
