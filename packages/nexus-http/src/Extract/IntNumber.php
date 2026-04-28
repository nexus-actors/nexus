<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Extract;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Override;

use function ctype_digit;
use function preg_match;

/** @implements Extractor<int> */
final readonly class IntNumber implements Extractor
{
    #[Override]
    public function fromSegment(string $segment): int
    {
        if (!ctype_digit($segment) && preg_match('/^-?\d+$/', $segment) !== 1) {
            throw new ExtractorRejection("path segment '{$segment}'", 'expected integer');
        }

        return (int) $segment;
    }
}
