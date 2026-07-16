<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use UnexpectedValueException;

use function extension_loaded;
use function function_exists;
use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Low-level msgpack codec with automatic backend selection.
 *
 * Uses the native `ext-msgpack` PHP extension when available (faster),
 * and falls back to the pure-PHP `rybakit/msgpack` library otherwise.
 * Both backends produce wire-compatible bytes.
 *
 * Any failure from the underlying backend surfaces as {@see \Throwable}
 * to the caller (e.g. {@see \MessagePack\Exception\InsufficientDataException}
 * for truncated or garbage bytes in pure mode). Only non-array payloads are
 * wrapped explicitly in {@see UnexpectedValueException}.
 *
 * @internal
 *
 * @psalm-api
 *
 * @example
 *   $codec = new MsgpackCodec();
 *   $bytes = $codec->pack(['id' => 42, 'type' => 'user.created']);
 *   $data  = $codec->unpack($bytes); // ['id' => 42, 'type' => 'user.created']
 */
final readonly class MsgpackCodec
{
    private bool $useExtension;

    public function __construct(?bool $useExtension = null)
    {
        $this->useExtension = $useExtension ?? extension_loaded('msgpack');
    }

    /**
     * Serialize an array to msgpack bytes.
     *
     * @param array<mixed> $data
     */
    public function pack(array $data): string
    {
        // The function_exists() guard is what lets Psalm analyze the call without
        // ext-msgpack stubs; at runtime it is redundant with useExtension except
        // when a caller forces useExtension=true without the extension loaded, in
        // which case the wire-compatible pure backend takes over instead of a
        // fatal undefined-function error.
        if ($this->useExtension && function_exists('msgpack_pack')) {
            /** @var mixed $packed untyped ext boundary — validated by the is_string() guard below */
            $packed = msgpack_pack($data);

            if (is_string($packed)) {
                return $packed;
            }
        }

        // rybakit defaults to FORCE_FLOAT64 — floats round-trip bit-exact
        return new Packer()->pack($data);
    }

    /**
     * Deserialize msgpack bytes to an array.
     *
     * @return array<mixed>
     *
     * @throws UnexpectedValueException when the decoded value is not an array
     *                                  or when trailing bytes are detected (pure path only)
     *
     * @note The native ext-msgpack backend does not detect trailing bytes — this is a pure-path-only check.
     */
    public function unpack(string $bytes): array
    {
        // The function_exists() guard mirrors pack(): it lets Psalm analyze the
        // call without ext-msgpack stubs and routes to the pure backend when the
        // extension is unavailable.
        if ($this->useExtension && function_exists('msgpack_unpack')) {
            // The @ matters: ext-msgpack emits a PHP warning (php_msgpack_unserialize)
            // on malformed or trailing bytes instead of failing cleanly. This method
            // decodes untrusted network input, so garbage must surface only through the
            // is_array() check below — never as log noise, and never as a Throwable
            // under a warning-to-exception error handler.
            /** @var mixed $result untyped ext boundary — validated by the is_array() guard below */
            $result = @msgpack_unpack($bytes);
        } else {
            $unpacker = new BufferUnpacker($bytes);
            /** @var mixed $result unpack() decodes untrusted bytes — validated by the is_array() guard below */
            $result = $unpacker->unpack();

            if ($unpacker->hasRemaining()) {
                throw new UnexpectedValueException(sprintf(
                    'Unexpected trailing bytes after msgpack value (%d bytes remaining).',
                    $unpacker->getRemainingCount(),
                ));
            }
        }

        if (!is_array($result)) {
            throw new UnexpectedValueException(sprintf(
                'Expected array from msgpack decoding, got %s.',
                get_debug_type($result),
            ));
        }

        return $result;
    }
}
