<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Formatter;

use DateTimeImmutable;
use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Record;
use Override;

use function json_encode;
use function sprintf;
use function strtoupper;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @psalm-api
 *
 * Monolog-style single-line format:
 *   [2026-06-14T13:50:01.234Z] app.INFO: user logged in {"userId":42}
 *
 * Context is JSON-encoded after the message. Empty context is omitted.
 */
final class LineFormatter implements Formatter
{
    /**
     * @psalm-suppress InvalidOperand — int/float arithmetic on millisecond fraction.
     */
    #[Override]
    public function format(Record $record): string
    {
        $millis = (int) (($record->timestamp - (int) $record->timestamp) * 1000);
        $iso = (new DateTimeImmutable('@' . (int) $record->timestamp))->format('Y-m-d\\TH:i:s');
        $timestamp = sprintf('%s.%03dZ', $iso, $millis);
        $level = strtoupper($record->level->toPsr3());
        $merged = $record->context + $record->extra;
        $tail = $merged === []
            ? ''
            : ' ' . self::encode($merged);

        return "[{$timestamp}] {$record->channel}.{$level}: {$record->message}{$tail}";
    }

    /** @param array<string, mixed> $context */
    private static function encode(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false
            ? '[]'
            : $encoded;
    }
}
