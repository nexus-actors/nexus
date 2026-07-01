<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use JsonException;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\Forfeit;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GetSnapshot;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\JoinGame;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\MakeMove;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameDomainException;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\MessageSerializer;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Wire codec for the WebSocket protocol.
 *
 * Client frames are `{ "type": "<join|move|forfeit|snapshot>", ... }`.
 * The codec peeks at the discriminator, strips it, then hands the rest
 * to the injected {@see MessageSerializer} (Valinor) — which is strict
 * about unknown keys — to map into the pure domain command class. A
 * command IS the wire shape; there are no per-action wire DTOs.
 *
 * Outbound frames are `{ "type": ..., "data": ... }` via {@see WireEnvelope}.
 */
final readonly class ClientFrameCodec
{
    /** @var array<string, class-string<GameCommand>> */
    private const array COMMANDS = [
        'forfeit' => Forfeit::class,
        'join' => JoinGame::class,
        'move' => MakeMove::class,
        'snapshot' => GetSnapshot::class,
    ];

    public function __construct(private MessageSerializer $serializer) {}

    /**
     * Returns `null` for unrecognised, malformed, or domain-invalid
     * frames — the channel actor then surfaces an error frame to the
     * offending client without knowing why.
     */
    public function decode(string $payload): ?GameCommand
    {
        $raw = self::decodeJson($payload);

        if ($raw === null) {
            return null;
        }

        $type = $raw['type'] ?? null;

        if (!is_string($type)) {
            return null;
        }

        $class = self::COMMANDS[$type] ?? null;

        if ($class === null) {
            return null;
        }

        unset($raw['type']);
        $body = (string) json_encode($raw);

        try {
            /** @var GameCommand */
            return $this->serializer->deserialize($body, $class);
        } catch (GameDomainException | MessageDeserializationException) {
            return null;
        }
    }

    public function encode(GameSnapshot $snapshot): string
    {
        return $this->serializer->serialize(new WireEnvelope('snapshot', $snapshot));
    }

    public function encodeError(string $message): string
    {
        return $this->serializer->serialize(new WireEnvelope('error', new ErrorPayload($message)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(string $payload): ?array
    {
        try {
            /** @var mixed $raw */
            $raw = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($raw)
            ? $raw
            : null;
    }
}
