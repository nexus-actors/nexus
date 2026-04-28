<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request as SwRequest;

final readonly class SwooleRequestConverter
{
    public function __construct(private Psr17Factory $factory = new Psr17Factory()) {}

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedOperand
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    public function toPsrRequest(SwRequest $sw): ServerRequestInterface
    {
        $server = $sw->server ?? [];
        $method = $server['request_method'] ?? 'GET';
        $path   = $server['request_uri'] ?? '/';
        $query  = $server['query_string'] ?? '';
        $suffix = $query !== ''
            ? "?{$query}"
            : '';
        $uri    = $this->factory->createUri($path . $suffix);

        $request = $this->factory->createServerRequest($method, $uri, $server);

        foreach ($sw->header ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($sw->cookie !== null && $sw->cookie !== []) {
            $request = $request->withCookieParams($sw->cookie);
        }

        if ($sw->get !== null && $sw->get !== []) {
            $request = $request->withQueryParams($sw->get);
        }

        if ($sw->post !== null && $sw->post !== []) {
            $request = $request->withParsedBody($sw->post);
        }

        $raw = $sw->rawContent();

        if ($raw !== '' && $raw !== false) {
            $request = $request->withBody($this->factory->createStream((string) $raw));
        }

        return $request;
    }
}
