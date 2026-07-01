<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

final readonly class Config
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
