<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function strtolower;

/**
 * @psalm-api
 *
 * Rejects requests whose body exceeds the configured byte limit with
 * 413 Payload Too Large. Trusts the Content-Length header for upfront
 * rejection; for streaming/chunked bodies, the body stream's
 * getSize() is consulted (PSR-7 returns null for unknown sizes, in
 * which case the limit is enforced after the body is read).
 *
 * Layer this as global middleware OUTSIDE any body parser so oversized
 * bodies never reach JSON decoding or the FromBody attribute mapper.
 *
 *   $app->middleware(new BodySizeLimitMiddleware(maxBytes: 10 * 1024 * 1024));
 *
 * Per-route limits override the global by registering this middleware
 * on the route:
 *
 *   $app->post('/upload', UploadHandler::class)
 *       ->middleware(new BodySizeLimitMiddleware(maxBytes: 100 * 1024 * 1024));
 */
final class BodySizeLimitMiddleware implements MiddlewareInterface
{
    private readonly ResponseFactoryInterface $responseFactory;
    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly int $maxBytes,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        // Default to Psr17Factory which implements both interfaces.
        // Callers that pass a custom response factory should pass a matching
        // stream factory so 413 responses use a consistent stream impl.
        if ($responseFactory === null || $streamFactory === null) {
            $default = new Psr17Factory();
            $this->responseFactory = $responseFactory ?? $default;
            $this->streamFactory = $streamFactory ?? $default;
        } else {
            $this->responseFactory = $responseFactory;
            $this->streamFactory = $streamFactory;
        }
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isBodyMethod($request->getMethod())) {
            return $handler->handle($request);
        }

        $declared = $this->declaredLength($request);

        if ($declared !== null && $declared > $this->maxBytes) {
            return $this->rejectResponse();
        }

        $bodySize = $request->getBody()->getSize();

        if ($bodySize !== null && $bodySize > $this->maxBytes) {
            return $this->rejectResponse();
        }

        return $handler->handle($request);
    }

    private function isBodyMethod(string $method): bool
    {
        return match (strtolower($method)) {
            'post', 'put', 'patch', 'delete' => true,
            default => false,
        };
    }

    private function declaredLength(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('Content-Length');

        if ($header === '' || !ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    private function rejectResponse(): ResponseInterface
    {
        $body = $this->streamFactory->createStream(
            sprintf('{"error":"payload too large","maxBytes":%d}', $this->maxBytes),
        );

        return $this->responseFactory
            ->createResponse(413, 'Payload Too Large')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
