<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Payload;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Throwable;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * @psalm-api
 *
 * Typed, forward-compatible reader over an unpacked msgpack map, shared by the cluster's hand-rolled
 * control-frame codecs ({@see HandshakeCodec}, {@see HandshakeAckCodec}, {@see GossipPayloadCodec},
 * {@see LeavePayloadCodec}). It exists so those codecs can stay off Valinor — the cluster wire
 * protocol is a fixed set of internal VOs on the hot gossip/heartbeat path, where the mapper's
 * reflection-driven hydration is pure overhead — while still validating every field taken off the
 * network before a VO is built.
 *
 * Forward compatibility: fields are resolved BY KEY with sensible defaults, and unknown keys are
 * ignored, so a newer protocol version can add a field without breaking an older reader.
 *
 * Trust boundary: the bytes come straight off the wire. Every accessor type-checks and throws
 * {@see MessageDeserializationException} on a mismatch, which frame ingress treats as an undecodable
 * frame (dropped and counted, never routed).
 */
final readonly class MsgpackReader
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(private array $data, private string $type) {}

    public static function from(string $bytes, MsgpackCodec $codec, string $type): self
    {
        try {
            // MsgpackCodec::unpack() already guarantees an array (it throws on a non-array root).
            $data = $codec->unpack($bytes);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        return new self($data, $type);
    }

    /**
     * @param array<array-key, mixed> $data An already-unpacked sub-map (e.g. one gossip member).
     */
    public static function fromArray(array $data, string $type): self
    {
        return new self($data, $type);
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be an integer.");
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be a string.");
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be a string or null.");
        }

        return $value;
    }

    public function intOr(string $key, int $default): int
    {
        $value = $this->data[$key] ?? $default;

        if (!is_int($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be an integer.");
        }

        return $value;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if ($value !== null && !is_int($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be an integer or null.");
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        if (!is_bool($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be a boolean.");
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be a map.");
        }

        $map = [];

        /** @var mixed $v Msgpack wire boundary: untyped until validated entry-by-entry below. */
        foreach ($value as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                throw new MessageDeserializationException(
                    $this->type,
                    "Field '{$key}' must be a string-to-string map.",
                );
            }

            $map[$k] = $v;
        }

        return $map;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function listOfMaps(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            throw new MessageDeserializationException($this->type, "Field '{$key}' must be a list.");
        }

        $list = [];

        /** @var mixed $entry Msgpack wire boundary: untyped; each entry is validated by the caller. */
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new MessageDeserializationException($this->type, "Field '{$key}' must be a list of maps.");
            }

            $list[] = $entry;
        }

        return $list;
    }
}
