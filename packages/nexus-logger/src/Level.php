<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

use InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * @psalm-api
 *
 * PSR-3 log levels as an int-backed enum so they can be ordered for
 * threshold filtering. The integer ordering matches RFC 5424 severity:
 * Emergency is the highest (8), Debug the lowest (1). A record at level X
 * is recorded when X >= the logger's configured minimum.
 */
enum Level: int
{
    case Debug = 1;
    case Info = 2;
    case Notice = 3;
    case Warning = 4;
    case Error = 5;
    case Critical = 6;
    case Alert = 7;
    case Emergency = 8;

    public static function fromPsr3(string $level): self
    {
        return match ($level) {
            LogLevel::ALERT => self::Alert,
            LogLevel::CRITICAL => self::Critical,
            LogLevel::DEBUG => self::Debug,
            LogLevel::EMERGENCY => self::Emergency,
            LogLevel::ERROR => self::Error,
            LogLevel::INFO => self::Info,
            LogLevel::NOTICE => self::Notice,
            LogLevel::WARNING => self::Warning,
            default => throw new InvalidArgumentException("Unknown PSR-3 log level: {$level}"),
        };
    }

    public function toPsr3(): string
    {
        return match ($this) {
            self::Alert => LogLevel::ALERT,
            self::Critical => LogLevel::CRITICAL,
            self::Debug => LogLevel::DEBUG,
            self::Emergency => LogLevel::EMERGENCY,
            self::Error => LogLevel::ERROR,
            self::Info => LogLevel::INFO,
            self::Notice => LogLevel::NOTICE,
            self::Warning => LogLevel::WARNING,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
