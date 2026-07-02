<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function json_encode;

/**
 * Catch-all exception mapper. Logs the crash and returns a stable
 * JSON error to the client — the internal message stays server-side.
 */
final readonly class JsonExceptionRenderer
{
    public function __construct(private LoggerInterface $log) {}

    public function __invoke(Throwable $e): ResponseInterface
    {
        $this->log->error('unhandled exception', [
            'error' => $e::class . ': ' . $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return new Psr7Response(
            500,
            ['content-type' => 'application/json'],
            (string) json_encode(['error' => 'internal server error']),
        );
    }
}
