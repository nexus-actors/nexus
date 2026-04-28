<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function max;
use function microtime;

final readonly class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(public LoggerInterface $logger) {}

    /** @psalm-suppress InvalidOperand */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = (int) (microtime(true) * 1000);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error('http_request_failed', [
                'class' => $e::class,
                'error' => $e->getMessage(),
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);

            throw $e;
        }

        $level = match (true) {
            $response->getStatusCode() >= 500 => 'error',
            $response->getStatusCode() >= 400 => 'notice',
            default => 'info',
        };

        $this->logger->log($level, 'http_request', [
            'durationMs' => max(0, (int) (microtime(true) * 1000) - $start),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
