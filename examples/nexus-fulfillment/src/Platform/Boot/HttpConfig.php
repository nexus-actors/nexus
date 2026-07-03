<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class HttpConfig
{
    public function __construct(public string $host, public int $port, public int $workers) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('FULFILLMENT_HTTP_HOST', '0.0.0.0'),
            port: Env::int('FULFILLMENT_HTTP_PORT', 8080),
            // Single worker until entity->process routing is sticky; the
            // event store's single-writer guarantee depends on it.
            workers: Env::int('FULFILLMENT_WORKERS', 1),
        );
    }
}
