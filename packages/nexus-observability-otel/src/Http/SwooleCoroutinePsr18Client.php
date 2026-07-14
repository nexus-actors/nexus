<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Http;

use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as CoroutineHttpClient;

use function extension_loaded;
use function implode;
use function is_string;
use function sprintf;

/**
 * @psalm-api
 *
 * PSR-18 HTTP client that uses `Swoole\Coroutine\Http\Client` when called inside a
 * Swoole coroutine and delegates to a fallback client everywhere else.
 *
 * Why this exists: under `SWOOLE_HOOK_ALL` BOTH generic PHP HTTP client paths are
 * broken for OTLP export — the userland curl hook rejects `CURLOPT_SHARE` (used by
 * symfony's curl client), and the hooked `http://` stream wrapper fails outright
 * ("Failed to parse address") — so every in-coroutine export silently dies after
 * retries. The coroutine-native client bypasses runtime hooks entirely and yields
 * to the scheduler during I/O. Outside a coroutine (boot, shutdown flush after the
 * reactor exits) the hooks do not apply and the fallback client works as-is.
 */
final readonly class SwooleCoroutinePsr18Client implements ClientInterface
{
    public function __construct(
        private ClientInterface $fallback,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private float $timeoutSeconds,
    ) {}

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() < 0) {
            return $this->fallback->sendRequest($request);
        }

        $uri = $request->getUri();
        $secure = $uri->getScheme() === 'https';
        $port = $uri->getPort() ?? ($secure
            ? 443
            : 80);

        $client = new CoroutineHttpClient($uri->getHost(), $port, $secure);
        $client->set(['timeout' => $this->timeoutSeconds]);

        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $client->setMethod($request->getMethod());
        $client->setHeaders($headers);

        $body = (string) $request->getBody();

        if ($body !== '') {
            $client->setData($body);
        }

        $path = $uri->getPath() === ''
            ? '/'
            : $uri->getPath();

        if ($uri->getQuery() !== '') {
            $path .= '?' . $uri->getQuery();
        }

        $client->execute($path);
        $statusCode = (int) $client->statusCode;
        $responseBody = is_string($client->body)
            ? $client->body
            : '';
        /** @var array<string, string>|null $responseHeaders */
        $responseHeaders = $client->headers;
        $client->close();

        if ($statusCode < 0) {
            throw new SwooleHttpNetworkException(
                sprintf(
                    'Swoole coroutine HTTP request to %s failed (statusCode=%d, errCode=%d): %s',
                    (string) $uri,
                    $statusCode,
                    (int) $client->errCode,
                    is_string($client->errMsg)
                        ? $client->errMsg
                        : '',
                ),
                $request,
            );
        }

        $response = $this->responseFactory
            ->createResponse($statusCode)
            ->withBody($this->streamFactory->createStream($responseBody));

        foreach ($responseHeaders ?? [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
