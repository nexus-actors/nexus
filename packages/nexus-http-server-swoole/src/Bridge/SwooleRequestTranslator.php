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
 *
 * Swoole\Http\Request exposes `$server`, `$header`, `$cookie`, `$get`,
 * `$post`, `$files` as untyped properties (the stubs don't model them
 * precisely). The runtime guarantees these are arrays or null; every access
 * is narrowed with an explicit runtime guard.
 */
final class SwooleRequestTranslator
{
    public static function toPsr7(SwooleRequest $req): ServerRequestInterface
    {
        /** @var mixed $rawServer */
        $rawServer = $req->server;
        $server = is_array($rawServer)
            ? $rawServer
            : [];
        $method = strtoupper(self::stringValue($server, 'request_method', 'GET'));
        $path   = self::stringValue($server, 'request_uri', '/');
        $query  = self::stringValue($server, 'query_string', '');
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

        /** @var mixed $rawHeaders */
        $rawHeaders = $req->header;

        $request = new ServerRequest(
            $method,
            $uri,
            is_array($rawHeaders)
                ? $rawHeaders
                : [],
            $body,
            $version,
            $server,
        );

        /** @var mixed $cookies */
        $cookies = $req->cookie;

        if (is_array($cookies) && $cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }

        /** @var mixed $get */
        $get = $req->get;

        if (is_array($get) && $get !== []) {
            $request = $request->withQueryParams($get);
        }

        /** @var mixed $post */
        $post = $req->post;

        if (is_array($post) && $post !== []) {
            $request = $request->withParsedBody($post);
        }

        /** @var mixed $files */
        $files = $req->files;

        if (is_array($files) && $files !== []) {
            $request = $request->withUploadedFiles(self::buildUploadedFiles($files));
        }

        return $request;
    }

    /** @param array<array-key, mixed> $server */
    private static function extractProtocolVersion(array $server): string
    {
        $protocol = self::stringValue($server, 'server_protocol', 'HTTP/1.1');

        if (str_starts_with($protocol, 'HTTP/')) {
            return substr($protocol, 5);
        }

        return '1.1';
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, UploadedFileInterface>
     */
    private static function buildUploadedFiles(array $files): array
    {
        $out = [];

        /** @var mixed $file */
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            $out[$name] = new UploadedFile(
                self::stringValue($file, 'tmp_name', ''),
                self::intValue($file, 'size', 0),
                self::intValue($file, 'error', UPLOAD_ERR_OK),
                isset($file['name'])
                    ? self::stringValue($file, 'name', '')
                    : null,
                isset($file['type'])
                    ? self::stringValue($file, 'type', '')
                    : null,
            );
        }

        return $out;
    }

    /** @param array<array-key, mixed> $values */
    private static function intValue(array $values, string $key, int $default): int
    {
        /** @var mixed $value */
        $value = $values[$key] ?? null;

        return is_scalar($value)
            ? (int) $value
            : $default;
    }

    /** @param array<array-key, mixed> $values */
    private static function stringValue(array $values, string $key, string $default): string
    {
        /** @var mixed $value */
        $value = $values[$key] ?? null;

        return is_scalar($value)
            ? (string) $value
            : $default;
    }
}
