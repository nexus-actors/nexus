<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

final readonly class DbConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $dbname,
        public string $user,
        public string $password,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('FULFILLMENT_DB_HOST', 'db'),
            port: Env::int('FULFILLMENT_DB_PORT', 5432),
            dbname: Env::get('FULFILLMENT_DB_NAME', 'fulfillment'),
            user: Env::get('FULFILLMENT_DB_USER', 'fulfillment'),
            password: Env::get('FULFILLMENT_DB_PASS', 'fulfillment'),
        );
    }

    public function pdoDsn(): string
    {
        return "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};connect_timeout=2";
    }
}
