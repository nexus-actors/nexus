<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @psalm-api
 *
 * Outermost middleware by default. Catches everything below and runs the
 * mapper registry. Logs with PSR-3 (NullLogger if none supplied).
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ExceptionMapperRegistry $mappers,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            ($this->logger ?? new NullLogger())->error('HTTP exception', [
                'exception' => $e,
                'method'    => $request->getMethod(),
                'path'      => $request->getUri()->getPath(),
            ]);

            return $this->mappers->map($e, $request);
        }
    }
}
