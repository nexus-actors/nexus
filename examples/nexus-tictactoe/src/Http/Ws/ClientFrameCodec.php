<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

use JsonException;
use Monadial\Nexus\Example\TicTacToe\Domain\Exception\GameDomainException;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\ClientIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\ForfeitIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\JoinIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\MoveIntent;
use Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent\SnapshotIntent;
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
 * Inbound: `{ "type": "<join|move|forfeit|snapshot>", ... }` is peeked for
 * the discriminator, then the remainder is mapped by the injected
 * {@see MessageSerializer} (Valinor, strict on unknown keys) into a
 * {@see ClientIntent}. Intents carry only what the client is allowed to
 * assert — never a player id on a gameplay frame.
 *
 * Outbound: `{ "type": ..., "data": ... }` via {@see WireEnvelope}. The
 * broadcast snapshot uses {@see SnapshotPayload} (name-only seats) so a
 * player's capability token never leaves its owner.
 */
final readonly class ClientFrameCodec
{
    private const array INTENTS = [
        'forfeit' => ForfeitIntent::class,
        'join' => JoinIntent::class,
        'move' => MoveIntent::class,
        'snapshot' => SnapshotIntent::class,
    ];

    public function __construct(private MessageSerializer $serializer) {}

    /**
     * Returns `null` for unrecognised, malformed, or invalid frames — the
     * channel actor surfaces a generic error to the offending client
     * without echoing why (no internal detail leak).
     */
    public function decode(string $payload): ?ClientIntent
    {
        $raw = self::decodeJson($payload);

        if ($raw === null) {
            return null;
        }

        $type = $raw['type'] ?? null;

        if (!is_string($type)) {
            return null;
        }

        $class = self::INTENTS[$type] ?? null;

        if ($class === null) {
            return null;
        }

        unset($raw['type']);

        try {
            /** @var ClientIntent */
            return $this->serializer->deserialize((string) json_encode($raw), $class);
        } catch (GameDomainException | MessageDeserializationException) {
            return null;
        }
    }

    public function encodeSnapshot(GameSnapshot $snapshot): string
    {
        return $this->serializer->serialize(new WireEnvelope('snapshot', SnapshotPayload::of($snapshot)));
    }

    public function encodeWelcome(?string $mark, string $token): string
    {
        return $this->serializer->serialize(new WireEnvelope('welcome', new WelcomePayload($mark, $token)));
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
