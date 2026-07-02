<?php

declare(strict_types=1);

namespace Monadial\Nexus\Logger;

/**
 * @psalm-api
 *
 * Converts a Record into a string ready for a Handler's sink. Trailing
 * newlines are NOT included; the handler decides delimiters.
 */
interface Formatter
{
    public function format(Record $record): string;
}
