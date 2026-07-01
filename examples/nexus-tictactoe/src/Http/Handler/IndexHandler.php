<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Handler;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function file_get_contents;
use function sprintf;

/**
 * Serves the pre-built React SPA (public/dist/index.html).
 *
 * The file is loaded once at construction and held in memory for the
 * lifetime of the worker — no filesystem hit per request.
 */
final readonly class IndexHandler
{
    private string $body;

    public function __construct(string $indexPath)
    {
        $body = file_get_contents($indexPath);

        if ($body === false) {
            throw new RuntimeException(sprintf('cannot read SPA index at %s', $indexPath));
        }

        $this->body = $body;
    }

    public function __invoke(): ResponseInterface
    {
        return new Psr7Response(200, ['content-type' => 'text/html; charset=utf-8'], $this->body);
    }
}
