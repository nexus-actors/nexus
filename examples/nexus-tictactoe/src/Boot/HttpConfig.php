<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

final readonly class HttpConfig
{
    public function __construct(public string $host, public int $port, public int $threads) {}

    public static function fromEnv(): self
    {
        return new self(
            host: Env::get('TICTACTOE_HTTP_HOST', '0.0.0.0'),
            port: Env::int('TICTACTOE_HTTP_PORT', 8080),
            // Default 1 worker — the WebSocketChannelActor fans out only to
            // connections attached to THIS worker, so two players landing on
            // different workers would never see each other. Multi-worker
            // deploys need a pub/sub layer (LISTEN/NOTIFY, Redis) — out of
            // scope for this example. The `#[Version]` column on GameSession
            // makes the persistence side safe if you do scale out.
            threads: Env::int('TICTACTOE_THREADS', 1),
        );
    }
}
