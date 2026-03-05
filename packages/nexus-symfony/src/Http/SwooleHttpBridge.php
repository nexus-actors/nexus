<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Http;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SwooleHttpBridge
{
    public function toSymfonyRequest(SwooleRequest $req): Request
    {
        return Request::create(
            uri: $req->server['request_uri'] ?? '/',
            method: $req->server['request_method'] ?? 'GET',
            parameters: $req->get ?? [],
            cookies: $req->cookie ?? [],
            files: $this->normaliseFiles($req->files ?? []),
            server: $this->normaliseServer($req->server ?? [], $req->header ?? []),
            content: $req->rawContent() !== '' ? $req->rawContent() : null,
        );
    }

    public function sendSymfonyResponse(Response $response, SwooleResponse $res): void
    {
        $res->status($response->getStatusCode());

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $res->header($name, $value);
            }
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $res->end((string) ob_get_clean());

            return;
        }

        $res->end((string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private function normaliseFiles(array $files): array
    {
        return $files;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function normaliseServer(array $server, array $headers): array
    {
        $normalised = [];

        foreach ($server as $key => $value) {
            $normalised[strtoupper($key)] = $value;
        }

        foreach ($headers as $key => $value) {
            $normalised['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $normalised;
    }
}
