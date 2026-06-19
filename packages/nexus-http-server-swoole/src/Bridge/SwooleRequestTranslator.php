<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Bridge;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Swoole\Http\Request as SwooleRequest;

/**
 * @psalm-api
 *
 * Maps a Swoole\Http\Request into a PSR-7 ServerRequest backed by nyholm/psr7.
 */
final class SwooleRequestTranslator
{
    /**
     * Swoole\Http\Request exposes `$server`, `$header`, `$cookie`, `$get`,
     * `$post`, `$files` as plain `mixed` (the stubs don't model them
     * precisely). The runtime guarantees these are arrays or null and we
     * defend each access; suppressing Psalm here keeps the code linear.
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    public static function toPsr7(SwooleRequest $req): ServerRequestInterface
    {
        $server = $req->server ?? [];
        $method = strtoupper((string) ($server['request_method'] ?? 'GET'));
        $path   = (string) ($server['request_uri'] ?? '/');
        $query  = (string) ($server['query_string'] ?? '');
        $version = self::extractProtocolVersion($server);

        $uri = $path . (
            $query !== ''
                ? '?' . $query
                : ''
        );
        $rawBody = $req->rawContent();
        $body    = is_string($rawBody)
            ? $rawBody
            : '';

        $request = new ServerRequest(
            $method,
            $uri,
            $req->header ?? [],
            $body,
            $version,
            $server,
        );

        if (is_array($req->cookie) && $req->cookie !== []) {
            $request = $request->withCookieParams($req->cookie);
        }

        if (is_array($req->get) && $req->get !== []) {
            $request = $request->withQueryParams($req->get);
        }

        if (is_array($req->post) && $req->post !== []) {
            $request = $request->withParsedBody($req->post);
        }

        if (is_array($req->files) && $req->files !== []) {
            $request = $request->withUploadedFiles(self::buildUploadedFiles($req->files));
        }

        return $request;
    }

    /** @param array<string, mixed> $server */
    private static function extractProtocolVersion(array $server): string
    {
        $protocol = (string) ($server['server_protocol'] ?? 'HTTP/1.1');

        if (str_starts_with($protocol, 'HTTP/')) {
            return substr($protocol, 5);
        }

        return '1.1';
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface>
     */
    private static function buildUploadedFiles(array $files): array
    {
        $out = [];

        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            $out[$name] = new UploadedFile(
                (string) ($file['tmp_name'] ?? ''),
                (int) ($file['size'] ?? 0),
                (int) ($file['error'] ?? UPLOAD_ERR_OK),
                isset($file['name'])
                    ? (string) $file['name']
                    : null,
                isset($file['type'])
                    ? (string) $file['type']
                    : null,
            );
        }

        return $out;
    }
}
