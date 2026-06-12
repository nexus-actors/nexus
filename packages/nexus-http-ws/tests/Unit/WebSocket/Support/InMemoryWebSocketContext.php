<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support;

use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test double for WebSocketContext that records sends and tracks aliveness.
 */
final class InMemoryWebSocketContext implements WebSocketContext
{
    /** @var list<string> */
    public array $sentText = [];

    /** @var list<string> */
    public array $sentBinary = [];

    public int $pings = 0;

    public bool $closed = false;

    public int $closeCode = 0;

    public string $closeReason = '';

    private bool $alive = true;

    public function __construct(private readonly int $id, private readonly string $path = '/') {}

    public function id(): int
    {
        return $this->id;
    }

    public function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', $this->path);
    }

    public function send(string $text): void
    {
        $this->sentText[] = $text;
    }

    public function sendBinary(string $data): void
    {
        $this->sentBinary[] = $data;
    }

    public function sendPing(): void
    {
        $this->pings++;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->alive = false;
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }
}
