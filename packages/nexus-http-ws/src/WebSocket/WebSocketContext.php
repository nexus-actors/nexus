<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
interface WebSocketContext
{
    public function id(): int;

    public function request(): ServerRequestInterface;

    public function send(string $text): void;

    public function sendBinary(string $data): void;

    public function sendPing(): void;

    public function close(int $code = 1000, string $reason = ''): void;

    public function isAlive(): bool;
}
