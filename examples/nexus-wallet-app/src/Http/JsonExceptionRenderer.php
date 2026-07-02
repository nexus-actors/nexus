<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function json_encode;

/**
 * Catch-all exception renderer registered via $app->onException(Throwable, ...).
 *
 * Logs the exception class, message, location, and stack trace, then
 * returns a 500 JSON body. The framework's default catch-all swallows
 * the log line — overriding lets us actually SEE handler crashes in
 * `docker compose logs`.
 */
final readonly class JsonExceptionRenderer
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(Throwable $e): ResponseInterface
    {
        $this->logger->error('handler exception', [
            'class' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return new Response(
            500,
            ['content-type' => 'application/json'],
            (string) json_encode([
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]),
        );
    }
}
