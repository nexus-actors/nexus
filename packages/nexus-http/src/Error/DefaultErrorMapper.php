<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Error;

use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\MaxRetriesExceededException;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\RequestCtx;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function implode;

final readonly class DefaultErrorMapper implements ErrorMapper
{
    #[Override]
    public function map(Throwable $error, RequestCtx $ctx): ResponseInterface
    {
        [$status, $code, $message, $extraHeaders] = match (true) {
            $error instanceof MethodNotAllowedRejection => [
                $error->status,
                $error->code,
                $error->getMessage(),
                ['Allow' => implode(', ', $error->allowed)],
            ],
            $error instanceof RouteRejection => [$error->status, $error->code, $error->getMessage(), []],
            $error instanceof AskTimeoutException => [504, 'ask_timeout', $error->getMessage(), []],
            $error instanceof MailboxClosedException => [503, 'mailbox_closed', $error->getMessage(), []],
            $error instanceof MaxRetriesExceededException => [503, 'max_retries', $error->getMessage(), []],
            default => [500, 'internal_error', 'internal server error', []],
        };

        $marshaller = $ctx->marshallerFor($ctx->negotiate());
        $payload = [
            'error' => $code,
            'message' => $message,
            'requestId' => $ctx->request()->getHeaderLine('X-Request-Id'),
        ];
        $body = $marshaller->marshal($payload);

        $response = (new Response($status))
            ->withHeader('Content-Type', (string) $marshaller->mediaType())
            ->withBody(Stream::create($body));

        foreach ($extraHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
