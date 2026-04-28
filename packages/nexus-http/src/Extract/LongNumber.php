<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;

use function preg_match;

/** @implements Extractor<int> */
final readonly class LongNumber implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): int
    {
        if (preg_match('/^-?\d+$/', $segment) !== 1) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected long integer');
        }

        return (int) $segment;
    }
}
