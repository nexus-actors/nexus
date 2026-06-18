<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

final readonly class WalletHttpConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public int $threads,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('WALLET_HTTP_HOST', '0.0.0.0'),
            port: Env::int('WALLET_HTTP_PORT', 8080),
            threads: Env::int('WALLET_THREADS', 4),
        );
    }
}
