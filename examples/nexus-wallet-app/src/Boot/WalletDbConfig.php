<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use function in_array;

/**
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final readonly class WalletDbConfig
{
    private const array ALLOWED_DRIVERS = [
        'ibm_db2', 'mysqli', 'oci8',
        'pdo_mysql', 'pdo_oci', 'pdo_pgsql', 'pdo_sqlite', 'pdo_sqlsrv',
        'pgsql', 'sqlite3', 'sqlsrv',
    ];

    /**
     * @param 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv' $driver
     */
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
        $driver = Env::get('WALLET_DB_DRIVER', 'pdo_pgsql');
        assert(in_array($driver, self::ALLOWED_DRIVERS, true), "Unknown WALLET_DB_DRIVER: {$driver}");

        return new self(
            driver: $driver,
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
     * @return array{
     *     dbname: string,
     *     driver: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *     host: string,
     *     password: string,
     *     port: int,
     *     user: string,
     * }
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
