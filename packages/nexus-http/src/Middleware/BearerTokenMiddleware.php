<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function hash_equals;
use function json_encode;
use function str_starts_with;
use function substr;

use const JSON_THROW_ON_ERROR;

final readonly class BearerTokenMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedTokens */
    public function __construct(public array $allowedTokens, public string $headerName = 'Authorization') {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine($this->headerName);

        if (!str_starts_with($header, 'Bearer ')) {
            return $this->reject('missing_token');
        }

        $token = substr($header, 7);

        foreach ($this->allowedTokens as $valid) {
            if (hash_equals($valid, $token)) {
                return $handler->handle($request);
            }
        }

        return $this->reject('invalid_token');
    }

    private function reject(string $code): Response
    {
        $body = json_encode(['error' => $code], JSON_THROW_ON_ERROR);

        return (new Response(401, ['Content-Type' => 'application/json']))
            ->withBody(Stream::create($body));
    }
}
