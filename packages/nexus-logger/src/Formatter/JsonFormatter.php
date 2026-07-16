<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger\Formatter;

use DateTimeImmutable;
use Monadial\Nexus\Logger\Formatter;
use Monadial\Nexus\Logger\Record;
use Override;

use function fmod;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @psalm-api
 *
 * One JSON object per record (ndjson). Keys are alphabetically sorted so
 * the output is byte-stable across runs.
 */
final class JsonFormatter implements Formatter
{
    #[Override]
    public function format(Record $record): string
    {
        $millis = (int) (fmod($record->timestamp, 1.0) * 1000.0);
        $iso = (new DateTimeImmutable('@' . (int) $record->timestamp))->format('Y-m-d\\TH:i:s');
        $payload = [
            'channel' => $record->channel,
            'context' => $record->context,
            'extra' => $record->extra,
            'level' => $record->level->toPsr3(),
            'message' => $record->message,
            'timestamp' => sprintf('%s.%03dZ', $iso, $millis),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false
            ? '{}'
            : $encoded;
    }
}
