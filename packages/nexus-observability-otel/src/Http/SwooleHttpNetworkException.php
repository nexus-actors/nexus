<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * @psalm-api
 *
 * PSR-18 network failure raised by {@see SwooleCoroutinePsr18Client} when the
 * coroutine HTTP client cannot reach the collector (connect failure, timeout,
 * or server reset). The OTel transport catches it and applies its retry policy.
 */
final class SwooleHttpNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
