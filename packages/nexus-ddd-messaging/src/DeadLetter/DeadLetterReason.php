<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\DeadLetter;

/**
 * @psalm-api
 *
 * EIP distinguishes Dead Letter Channel (delivery failure — replayable
 * once root cause is fixed) from Invalid Message Channel (content failure
 * — never replayable).
 */
enum DeadLetterReason: string
{
    case Expired = 'expired';
    case Invalid_DeserializationFailure = 'invalid-deserialization-failure';
    case Invalid_HandlerSignatureMismatch = 'invalid-handler-signature-mismatch';
    case Invalid_NoHandlerRegistered = 'invalid-no-handler-registered';
    case Invalid_SchemaValidationFailure = 'invalid-schema-validation-failure';
    case TerminalFailure = 'terminal-failure';
    case Timeout = 'timeout';
    case TransientFailureExhausted = 'transient-failure-exhausted';

    public function isReplayable(): bool
    {
        return match ($this) {
            self::Expired,
            self::TerminalFailure,
            self::Timeout,
            self::TransientFailureExhausted => true,
            self::Invalid_DeserializationFailure,
            self::Invalid_HandlerSignatureMismatch,
            self::Invalid_NoHandlerRegistered,
            self::Invalid_SchemaValidationFailure => false,
        };
    }
}
