<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

final readonly class WalletDbConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $dbname,
        public string $user,
        public string $password,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            driver: Env::get('WALLET_DB_DRIVER', 'pdo_pgsql'),
            host: Env::get('WALLET_DB_HOST', 'db'),
            port: Env::int('WALLET_DB_PORT', 5432),
            dbname: Env::get('WALLET_DB_NAME', 'wallet'),
            user: Env::get('WALLET_DB_USER', 'wallet'),
            password: Env::get('WALLET_DB_PASS', 'wallet'),
        );
    }

    /**
     * Doctrine DBAL connection-params array, derived from the typed fields.
     *
     * @return array<string, mixed>
     */
    public function toDbalParams(): array
    {
        return [
            'dbname' => $this->dbname,
            'driver' => $this->driver,
            'host' => $this->host,
            'password' => $this->password,
            'port' => $this->port,
            'user' => $this->user,
        ];
    }
}
