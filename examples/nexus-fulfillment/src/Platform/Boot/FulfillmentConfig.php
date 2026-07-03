<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class FulfillmentConfig
{
    public function __construct(public HttpConfig $http, public DbConfig $db) {}

    public static function fromEnv(): self
    {
        return new self(
            http: HttpConfig::fromEnv(),
            db: DbConfig::fromEnv(),
        );
    }
}
