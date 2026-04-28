<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function is_array;
use function json_decode;

final readonly class RouteResult
{
    public function __construct(public ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function rawBody(): string
    {
        return (string) $this->response->getBody();
    }

    /** @return array<array-key, mixed> */
    public function jsonBody(): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($this->rawBody(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('response body is not a JSON object/array');
        }

        return $decoded;
    }
}
