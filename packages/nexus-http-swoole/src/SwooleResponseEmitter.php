<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Psr\Http\Message\ResponseInterface;

use function implode;

final readonly class SwooleResponseEmitter
{
    /**
     * Emit a PSR-7 response into a Swoole\Http\Response (or duck-typed equivalent
     * with status/header/end methods — see test).
     *
     * @psalm-suppress MixedMethodCall — duck-typed Swoole\Http\Response
     */
    public function emit(ResponseInterface $psr, object $sw): void
    {
        $sw->status($psr->getStatusCode());

        foreach ($psr->getHeaders() as $name => $values) {
            $sw->header($name, implode(', ', $values));
        }

        $sw->end((string) $psr->getBody());
    }
}
